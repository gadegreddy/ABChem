#!/usr/bin/env python3
"""
stereo_fetch.py — Stereospecific SMILES fetcher for AB Chem India
Called by api_stereo.php via proc_open (stdin JSON -> stdout JSON).

Queries FDA GSRS (priority 1) then ChEMBL (priority 2) for each
compound to find the best fully-defined stereochemical SMILES.
Uses RDKit to score stereo completeness (defined_centers / total_centers).

Input  (stdin):
    {
      "compounds": [
        {"id": 123, "name": "Rifamycin B", "cas": "13929-35-6",
         "inchi_key": "HJBWJAPEBGSQPR-VXKWHMMOSA-N",
         "smiles": "CC1/C=C/..."}
      ]
    }

Output (stdout):
    {
      "results": [
        {"id": 123, "found": true, "smiles_stereo": "...",
         "stereo_source": "gsrs", "total_centers": 3,
         "defined_centers": 3, "score": 1.0, "errors": []}
      ],
      "errors": []
    }
"""

import sys
import json
import time
import urllib.request
import urllib.parse
import urllib.error


# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

USER_AGENT   = "ABChem-StereoBot/1.0"
TIMEOUT      = 8          # seconds per HTTP request
GSRS_BASE    = "https://gsrs.ncats.nih.gov/api/v1/substances"
CHEMBL_BASE  = "https://www.ebi.ac.uk/chembl/api/data"
MIN_SCORE    = 0.5        # GSRS results below this fall through to ChEMBL


# ---------------------------------------------------------------------------
# RDKit helpers
# ---------------------------------------------------------------------------

def _import_rdkit():
    """Lazy-import RDKit; returns (Chem, rdMolDescriptors, ok)."""
    try:
        from rdkit import Chem
        from rdkit.Chem import rdMolDescriptors
        return Chem, rdMolDescriptors, True
    except ImportError:
        return None, None, False


def _validate_connectivity(fetched_smiles, original_inchi_key, Chem):
    """
    Verify that fetched_smiles has the same connectivity layer as the original
    compound's InChIKey (first 14-char segment = hash of formula + connections,
    excluding stereo and isotope layers).

    Returns True if they match, False if they differ (wrong compound).
    Returns True if validation cannot be performed (missing data, RDKit issue).
    """
    if not original_inchi_key or not fetched_smiles:
        return True
    orig_seg1 = original_inchi_key.split('-')[0] if '-' in original_inchi_key else ''
    if not orig_seg1 or len(orig_seg1) != 14:
        return True
    try:
        from rdkit.Chem.inchi import MolToInchi, InchiToInchiKey
        mol = Chem.MolFromSmiles(fetched_smiles)
        if mol is None:
            return True   # invalid SMILES — let score check reject it
        inchi_str = MolToInchi(mol)
        if not inchi_str:
            return True
        fetched_key = InchiToInchiKey(inchi_str)
        if not fetched_key:
            return True
        fetched_seg1 = fetched_key.split('-')[0]
        return orig_seg1 == fetched_seg1
    except Exception:
        return True       # InChI not available or failed — accept the candidate


def stereo_score(smiles, Chem, rdMolDescriptors):
    """
    Score how completely stereochemistry is defined in a SMILES string.

    Returns (score, defined_centers, total_centers).
      - Achiral molecules (0 stereocenters) return score=1.0 by convention.
      - Invalid SMILES returns (0.0, 0, 0).
    """
    if not smiles:
        return 0.0, 0, 0
    mol = Chem.MolFromSmiles(smiles)
    if mol is None:
        return 0.0, 0, 0
    mol = Chem.RemoveHs(mol)
    try:
        centers = rdMolDescriptors.FindMolChiralCenters(mol, includeUnassigned=True)
    except Exception:
        centers = []
    total = len(centers)
    if total == 0:
        return 1.0, 0, 0   # achiral molecule — fully defined by default
    defined = sum(1 for _, ch in centers if ch in ('R', 'S', 'r', 's'))
    return round(defined / total, 3), defined, total


# ---------------------------------------------------------------------------
# HTTP helper
# ---------------------------------------------------------------------------

def _get_json(url):
    """
    Fetch a URL and parse the JSON response.
    Returns (data_dict, error_string).  Never raises.
    """
    try:
        req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
        return json.loads(raw), None
    except urllib.error.HTTPError as e:
        return None, f"HTTP {e.code} for {url}"
    except urllib.error.URLError as e:
        return None, f"URL error for {url}: {e.reason}"
    except json.JSONDecodeError as e:
        return None, f"JSON decode error for {url}: {e}"
    except Exception as e:
        return None, f"Unexpected error for {url}: {e}"


# ---------------------------------------------------------------------------
# GSRS search
# ---------------------------------------------------------------------------

def _gsrs_candidates(cas, name):
    """
    Query FDA GSRS by CAS first, then by name if needed.
    Returns a list of (smiles, source_label) tuples from all matching records.
    """
    candidates = []

    for query, label in [(cas, f"GSRS CAS={cas}"), (name, f"GSRS name={name!r}")]:
        if not query:
            continue
        url = (
            GSRS_BASE
            + "/search?"
            + urllib.parse.urlencode({"q": query, "top": 3})
        )
        data, err = _get_json(url)
        if err or not isinstance(data, dict):
            continue

        content = data.get("content") or []
        for item in content:
            smiles = (
                (item.get("structure") or {}).get("smiles") or ""
            ).strip()
            if smiles:
                candidates.append((smiles, "gsrs"))
        # Stop after CAS gives results
        if candidates:
            break

    return candidates


# ---------------------------------------------------------------------------
# ChEMBL search
# ---------------------------------------------------------------------------

def _chembl_candidates(name, cas):
    """
    Query ChEMBL by preferred name first, then by CAS synonym.
    Returns a list of (smiles, source_label) tuples.
    """
    candidates = []

    # Try by exact preferred name
    if name:
        url = (
            CHEMBL_BASE
            + "/molecule?"
            + urllib.parse.urlencode({"pref_name__iexact": name, "format": "json"})
        )
        data, err = _get_json(url)
        if not err and isinstance(data, dict):
            for mol in (data.get("molecules") or []):
                smiles = (
                    (mol.get("molecule_structures") or {}).get("canonical_smiles") or ""
                ).strip()
                if smiles:
                    candidates.append((smiles, "chembl"))

    # Try by CAS synonym if name search returned nothing
    if not candidates and cas:
        url = (
            CHEMBL_BASE
            + "/molecule?"
            + urllib.parse.urlencode({
                "molecule_synonyms__synonym__iexact": cas,
                "format": "json",
                "limit": 3,
            })
        )
        data, err = _get_json(url)
        if not err and isinstance(data, dict):
            for mol in (data.get("molecules") or []):
                smiles = (
                    (mol.get("molecule_structures") or {}).get("canonical_smiles") or ""
                ).strip()
                if smiles:
                    candidates.append((smiles, "chembl"))

    return candidates


# ---------------------------------------------------------------------------
# Per-compound processor
# ---------------------------------------------------------------------------

def process_compound(compound, Chem, rdMolDescriptors):
    """
    Find the best stereospecific SMILES for a single compound dict.
    Returns a result dict matching the output schema.
    """
    cid             = compound.get("id")
    name            = (compound.get("name")      or "").strip()
    cas             = (compound.get("cas")        or "").strip()
    orig_inchi_key  = (compound.get("inchi_key") or "").strip()
    errors          = []

    best_smiles   = None
    best_source   = None
    best_score    = 0.0
    best_defined  = 0
    best_total    = 0

    def _try_candidates(candidates):
        nonlocal best_smiles, best_source, best_score, best_defined, best_total
        for smiles, source in candidates:
            # Reject candidates whose connectivity doesn't match our compound
            if orig_inchi_key and not _validate_connectivity(smiles, orig_inchi_key, Chem):
                errors.append(
                    f"Connectivity mismatch from {source}: fetched SMILES '{smiles[:60]}' "
                    f"does not match InChIKey {orig_inchi_key} — skipped"
                )
                continue
            sc, defined, total = stereo_score(smiles, Chem, rdMolDescriptors)
            if sc > best_score:
                best_smiles  = smiles
                best_source  = source
                best_score   = sc
                best_defined = defined
                best_total   = total

    # ── Step 1: FDA GSRS ────────────────────────────────────────────────────
    try:
        gsrs_hits = _gsrs_candidates(cas, name)
        _try_candidates(gsrs_hits)
    except Exception as e:
        errors.append(f"GSRS error: {e}")

    # ── Step 2: ChEMBL (if GSRS result is below threshold or absent) ────────
    if best_score < MIN_SCORE:
        try:
            chembl_hits = _chembl_candidates(name, cas)
            _try_candidates(chembl_hits)
        except Exception as e:
            errors.append(f"ChEMBL error: {e}")

    # ── Build result ────────────────────────────────────────────────────────
    if best_smiles and best_score > 0.0:
        return {
            "id":              cid,
            "found":           True,
            "smiles_stereo":   best_smiles,
            "stereo_source":   best_source,
            "total_centers":   best_total,
            "defined_centers": best_defined,
            "score":           best_score,
            "errors":          errors,
        }
    else:
        return {
            "id":              cid,
            "found":           False,
            "smiles_stereo":   None,
            "stereo_source":   None,
            "total_centers":   0,
            "defined_centers": 0,
            "score":           0.0,
            "errors":          errors,
        }


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    top_errors = []
    results    = []

    # ── Parse stdin ──────────────────────────────────────────────────────────
    try:
        raw     = sys.stdin.read().strip()
        payload = json.loads(raw) if raw else {}
    except json.JSONDecodeError as e:
        sys.stdout.write(json.dumps({
            "results": [],
            "errors":  [f"JSON parse error on stdin: {e}"],
        }, ensure_ascii=False))
        sys.exit(0)
    except Exception as e:
        sys.stdout.write(json.dumps({
            "results": [],
            "errors":  [f"Input read error: {e}"],
        }, ensure_ascii=False))
        sys.exit(0)

    compounds = payload.get("compounds") or []
    if not compounds:
        sys.stdout.write(json.dumps({
            "results": [],
            "errors":  ["No compounds provided"],
        }, ensure_ascii=False))
        sys.exit(0)

    # ── Import RDKit ─────────────────────────────────────────────────────────
    Chem, rdMolDescriptors, rdkit_ok = _import_rdkit()
    if not rdkit_ok:
        sys.stdout.write(json.dumps({
            "results": [],
            "errors":  ["RDKit is not available on this server"],
        }, ensure_ascii=False))
        sys.exit(0)

    # ── Process each compound ────────────────────────────────────────────────
    for compound in compounds:
        try:
            result = process_compound(compound, Chem, rdMolDescriptors)
            results.append(result)
        except Exception as e:
            cid = compound.get("id")
            results.append({
                "id":              cid,
                "found":           False,
                "smiles_stereo":   None,
                "stereo_source":   None,
                "total_centers":   0,
                "defined_centers": 0,
                "score":           0.0,
                "errors":          [f"Unhandled error: {e}"],
            })

    # ── Emit output ──────────────────────────────────────────────────────────
    sys.stdout.write(json.dumps({
        "results": results,
        "errors":  top_errors,
    }, ensure_ascii=False))


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        # Absolute last-resort handler — must never crash silently
        sys.stdout.write(json.dumps({
            "results": [],
            "errors":  [f"Fatal error: {e}"],
        }, ensure_ascii=False))
