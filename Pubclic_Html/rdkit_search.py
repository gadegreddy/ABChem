#!/usr/bin/env python3
"""
rdkit_search.py — RDKit-powered structure search + utilities for AB Chem India
Called by api_structure_search.php via proc_open.

action: "search" (default)
  Input:  { query, search_type, threshold, products:[{id,smiles}] }
  Output: { results, errors, query_canonical, search_type, threshold, total }
  search_type: exact | substructure | similar

action: "validate"  [FEAT-12]
  Input:  { action:"validate", compounds:[{id,smiles}] }
  Output: { results:[{id,smiles,valid,canonical,error}], errors, total_valid, total_invalid }

action: "enrich"  [FEAT-13]
  Input:  { action:"enrich", smiles:"..." }
  Output: { valid, canonical, inchi, inchi_key, formula, mol_weight, errors }

action: "draw"  [FEAT-14]
  Input:  { action:"draw", smiles:"...", highlight_smiles:"...", format:"svg"|"png",
            width:300, height:200, cache_path:"/abs/path/to/save.svg" }
  Output: { valid, svg|png_base64, cached, errors }
"""

import sys
import json
import os
import base64


def _import_rdkit():
    try:
        from rdkit import Chem
        from rdkit.Chem import rdFingerprintGenerator, DataStructs, Descriptors
        from rdkit.Chem import rdMolDescriptors, Draw
        from rdkit.Chem.Draw import rdMolDraw2D
        try:
            from rdkit.Chem.inchi import MolToInchi, InchiToInchiKey
        except ImportError:
            from rdkit.Chem import MolToInchi, InchiToInchiKey
        return Chem, rdFingerprintGenerator, DataStructs, Descriptors, \
               rdMolDescriptors, Draw, rdMolDraw2D, MolToInchi, InchiToInchiKey, True
    except ImportError as e:
        return None, None, None, None, None, None, None, None, None, False


def action_validate(payload):
    """FEAT-12: Validate a list of SMILES strings."""
    Chem, *_, ok = _import_rdkit()
    if not ok:
        return {"results": [], "errors": ["RDKit not available"], "total_valid": 0, "total_invalid": 0}

    compounds = payload.get("compounds") or []
    results   = []
    for c in compounds:
        cid    = c.get("id")
        smiles = (c.get("smiles") or "").strip()
        if not smiles or smiles.upper() == "NA":
            results.append({"id": cid, "smiles": smiles, "valid": False, "error": "Empty SMILES"})
            continue
        mol = Chem.MolFromSmiles(smiles)
        if mol is None:
            results.append({"id": cid, "smiles": smiles, "valid": False, "error": "RDKit cannot parse SMILES"})
        else:
            results.append({
                "id":        cid,
                "smiles":    smiles,
                "valid":     True,
                "canonical": Chem.MolToSmiles(mol, canonical=True),
                "error":     None
            })

    total_valid   = sum(1 for r in results if r["valid"])
    total_invalid = len(results) - total_valid
    return {"results": results, "errors": [], "total_valid": total_valid, "total_invalid": total_invalid}


def action_enrich(payload):
    """FEAT-13: Generate identifiers from SMILES."""
    Chem, _, __, Descriptors, rdMolDescriptors, ___, ____, MolToInchi, InchiToInchiKey, ok = _import_rdkit()
    if not ok:
        return {"valid": False, "errors": ["RDKit not available"]}

    smiles = (payload.get("smiles") or "").strip()
    if not smiles:
        return {"valid": False, "errors": ["No SMILES provided"]}

    mol = Chem.MolFromSmiles(smiles)
    if mol is None:
        return {"valid": False, "errors": [f"Invalid SMILES: {smiles}"]}

    canonical = Chem.MolToSmiles(mol, canonical=True)
    inchi     = MolToInchi(mol) or ""
    inchi_key = InchiToInchiKey(inchi) if inchi else ""
    formula   = rdMolDescriptors.CalcMolFormula(mol)
    mw        = round(Descriptors.MolWt(mol), 4)

    return {
        "valid":     True,
        "canonical": canonical,
        "inchi":     inchi,
        "inchi_key": inchi_key,
        "formula":   formula,
        "mol_weight": mw,
        "errors":    []
    }


def action_stereo_check(payload):
    """FEAT-38: Analyse stereo quality of a batch of compounds.

    Input:  { action:"stereo_check", compounds:[{id, smiles, inchi_key}] }
    Output: { results:[{id, stereo_status, chiral_centers, defined_centers,
                        undefined_centers, stereo_bonds, inchikey_no_stereo, detail}],
              errors, total }
    stereo_status values:
      'achiral'   — no stereocenters in molecule
      'unverified'— has stereocenters; needs cross-DB verification
    """
    Chem, _, __, ___, rdMolDescriptors, ____, _____, ______, _______, ok = _import_rdkit()
    if not ok:
        return {"results": [], "errors": ["RDKit not available"], "total": 0}

    compounds = payload.get("compounds") or []
    results   = []
    errors    = []

    for c in compounds:
        cid       = c.get("id")
        smiles    = (c.get("smiles") or "").strip()
        inchi_key = (c.get("inchi_key") or "").strip()

        if not smiles or smiles.upper() == "NA":
            results.append({
                "id": cid, "stereo_status": None, "detail": "no_smiles",
                "chiral_centers": 0, "defined_centers": 0,
                "undefined_centers": 0, "stereo_bonds": 0, "inchikey_no_stereo": False
            })
            continue

        mol = Chem.MolFromSmiles(smiles)
        if mol is None:
            errors.append(f"ID {cid}: invalid SMILES")
            results.append({
                "id": cid, "stereo_status": None, "detail": "invalid_smiles",
                "chiral_centers": 0, "defined_centers": 0,
                "undefined_centers": 0, "stereo_bonds": 0, "inchikey_no_stereo": False
            })
            continue

        mol = Chem.RemoveHs(mol)

        # ── Chiral centres ────────────────────────────────────────────────
        try:
            centers = rdMolDescriptors.FindMolChiralCenters(mol, includeUnassigned=True)
        except Exception:
            centers = []

        total_centers     = len(centers)
        defined_centers   = sum(1 for _, ch in centers if ch in ('R', 'S', 'r', 's'))
        undefined_centers = total_centers - defined_centers

        # ── E/Z stereo bonds ──────────────────────────────────────────────
        stereo_bonds = 0
        try:
            si = Chem.FindPotentialStereo(mol)
            stereo_bonds = sum(
                1 for s in si
                if s.type == Chem.StereoType.Bond_Double
                and s.specified == Chem.StereoSpecified.Specified
            )
        except Exception:
            pass

        # ── InChIKey stereo-layer check ────────────────────────────────────
        # Middle segment "UHFFFAOYSA" = no stereo defined in InChI
        inchikey_no_stereo = False
        if inchi_key and '-' in inchi_key:
            parts = inchi_key.split('-')
            if len(parts) >= 2:
                inchikey_no_stereo = (parts[1] == 'UHFFFAOYSA')

        # ── Assign status ─────────────────────────────────────────────────
        if total_centers == 0 and stereo_bonds == 0:
            status = "achiral"
            detail = "no_stereocenters"
        elif undefined_centers == 0:
            status = "unverified"   # all centres defined but needs cross-DB confirmation
            detail = f"all_{total_centers}_defined_needs_crosscheck"
        else:
            status = "unverified"
            detail = f"{undefined_centers}_undefined_of_{total_centers}"

        if total_centers > 0 and inchikey_no_stereo:
            detail += "|inchikey_stereo_mismatch"

        results.append({
            "id":                  cid,
            "stereo_status":       status,
            "detail":              detail,
            "chiral_centers":      total_centers,
            "defined_centers":     defined_centers,
            "undefined_centers":   undefined_centers,
            "stereo_bonds":        stereo_bonds,
            "inchikey_no_stereo":  inchikey_no_stereo,
        })

    return {"results": results, "errors": errors, "total": len(results)}


def action_draw(payload):
    """FEAT-14: Generate SVG or PNG molecule image, optionally with substructure highlight."""
    Chem, _, __, ___, ____, Draw, rdMolDraw2D, _5, _6, ok = _import_rdkit()
    if not ok:
        return {"valid": False, "errors": ["RDKit not available"]}

    smiles     = (payload.get("smiles") or "").strip()
    hi_smiles  = (payload.get("highlight_smiles") or "").strip()
    fmt        = (payload.get("format") or "svg").lower()
    width      = int(payload.get("width")  or 300)
    height     = int(payload.get("height") or 200)
    cache_path = (payload.get("cache_path") or "").strip()

    if not smiles:
        return {"valid": False, "errors": ["No SMILES provided"]}

    mol = Chem.MolFromSmiles(smiles)
    if mol is None:
        return {"valid": False, "errors": [f"Invalid SMILES: {smiles}"]}

    # Remove explicit hydrogens — keeps drawings clean (skeletal structure style)
    mol = Chem.RemoveHs(mol)

    # Use CoordGen for better 2D layout, especially for macrocycles and fused rings
    try:
        from rdkit.Chem import rdDepictor
        rdDepictor.SetPreferCoordGen(True)
        rdDepictor.Compute2DCoords(mol)
    except Exception:
        pass  # Fall back to default RDKit layout if CoordGen unavailable

    # Substructure highlight atoms/bonds
    hit_atoms, hit_bonds = [], []
    if hi_smiles:
        query = Chem.MolFromSmiles(hi_smiles)
        if query and mol.HasSubstructMatch(query):
            hit_atoms = list(mol.GetSubstructMatch(query))
            hit_bonds = [
                bond.GetIdx() for bond in mol.GetBonds()
                if bond.GetBeginAtomIdx() in hit_atoms and bond.GetEndAtomIdx() in hit_atoms
            ]

    # Draw
    if fmt == "svg":
        drawer = rdMolDraw2D.MolDraw2DSVG(width, height)
    else:
        drawer = rdMolDraw2D.MolDraw2DCairo(width, height)

    opts = drawer.drawOptions()
    opts.addStereoAnnotation = True
    opts.padding = 0.15          # Add a bit of padding so atoms don't touch the border

    if hit_atoms:
        atom_cols  = {i: (0.9, 0.7, 0.1) for i in hit_atoms}
        bond_cols  = {i: (0.9, 0.7, 0.1) for i in hit_bonds}
        drawer.DrawMolecule(mol, highlightAtoms=hit_atoms, highlightBonds=hit_bonds,
                            highlightAtomColors=atom_cols, highlightBondColors=bond_cols)
    else:
        drawer.DrawMolecule(mol)
    drawer.FinishDrawing()

    result = {"valid": True, "errors": [], "cached": False}

    if fmt == "svg":
        svg = drawer.GetDrawingText()
        result["svg"] = svg
        if cache_path:
            os.makedirs(os.path.dirname(cache_path), exist_ok=True)
            with open(cache_path, "w") as f:
                f.write(svg)
            result["cached"] = True
    else:
        png = drawer.GetDrawingText()
        result["png_base64"] = base64.b64encode(png).decode("utf-8")
        if cache_path:
            os.makedirs(os.path.dirname(cache_path), exist_ok=True)
            with open(cache_path, "wb") as f:
                f.write(png)
            result["cached"] = True

    return result


def main():
    errors  = []
    results = []

    # ── Parse input ─────────────────────────────────────────────────────────
    try:
        raw     = sys.stdin.read().strip()
        payload = json.loads(raw) if raw else {}
    except json.JSONDecodeError as e:
        sys.stdout.write(json.dumps({
            "results": [], "errors": [f"JSON parse error: {e}"], "total": 0
        }))
        sys.exit(0)
    except Exception as e:
        sys.stdout.write(json.dumps({
            "results": [], "errors": [f"Input error: {e}"], "total": 0
        }))
        sys.exit(0)

    # ── Route by action ──────────────────────────────────────────────────────
    action = (payload.get("action") or "search").lower()

    if action == "validate":
        sys.stdout.write(json.dumps(action_validate(payload), ensure_ascii=False))
        sys.exit(0)

    if action == "enrich":
        sys.stdout.write(json.dumps(action_enrich(payload), ensure_ascii=False))
        sys.exit(0)

    if action == "draw":
        sys.stdout.write(json.dumps(action_draw(payload), ensure_ascii=False))
        sys.exit(0)

    if action == "stereo_check":
        sys.stdout.write(json.dumps(action_stereo_check(payload), ensure_ascii=False))
        sys.exit(0)

    # ── Original search action ───────────────────────────────────────────────
    query_smiles = (payload.get("query") or payload.get("smiles") or "").strip()
    search_type  = (payload.get("search_type") or "exact").lower()
    threshold    = float(payload.get("threshold") or 0.60)
    products     = payload.get("products") or []

    if not query_smiles:
        sys.stdout.write(json.dumps({
            "results": [], "errors": ["No query SMILES provided"],
            "query_canonical": "", "search_type": search_type, "threshold": threshold, "total": 0
        }))
        sys.exit(0)

    # ── Import RDKit ────────────────────────────────────────────────────────
    try:
        from rdkit import Chem
        from rdkit.Chem import rdFingerprintGenerator, DataStructs
        RDKIT_OK = True
    except ImportError as e:
        sys.stdout.write(json.dumps({
            "results": [],
            "errors": [f"RDKit not available: {e}"],
            "rdkit_missing": True,
            "query_canonical": "", "search_type": search_type, "threshold": threshold, "total": 0
        }))
        sys.exit(0)

    # ── Parse query molecule ────────────────────────────────────────────────
    query_mol = Chem.MolFromSmiles(query_smiles)
    if query_mol is None:
        sys.stdout.write(json.dumps({
            "results": [],
            "errors": [f"Invalid query SMILES: {query_smiles}"],
            "query_canonical": "", "search_type": search_type, "threshold": threshold, "total": 0
        }))
        sys.exit(0)

    query_canonical = Chem.MolToSmiles(query_mol, canonical=True)

    # ── Pre-compute for similarity search ───────────────────────────────────
    gen = None
    query_fp = None
    if search_type == "similar":
        gen      = rdFingerprintGenerator.GetMorganGenerator(radius=2, fpSize=2048)
        query_fp = gen.GetFingerprint(query_mol)

    # ── Search each product ─────────────────────────────────────────────────
    for p in products:
        prod_id     = p.get("id")
        prod_smiles = (p.get("smiles") or "").strip()

        if not prod_smiles or prod_smiles.upper() == "NA":
            continue

        prod_mol = Chem.MolFromSmiles(prod_smiles)
        if prod_mol is None:
            errors.append(f"ID {prod_id}: unparseable SMILES '{prod_smiles[:60]}'")
            continue

        prod_canonical = Chem.MolToSmiles(prod_mol, canonical=True)
        score   = 0.0
        matched = False

        # ── Exact match ─────────────────────────────────────────────────
        if search_type == "exact":
            if query_canonical == prod_canonical:
                matched = True
                score   = 100.0

        # ── Substructure search ──────────────────────────────────────────
        elif search_type == "substructure":
            if prod_mol.HasSubstructMatch(query_mol):
                matched = True
                # Score: ratio of query atoms to product atoms
                match = prod_mol.GetSubstructMatch(query_mol)
                q_atoms = len(match)
                p_atoms = max(prod_mol.GetNumAtoms(), 1)
                score   = round(100.0 * q_atoms / p_atoms, 1)
                score   = max(score, 50.0)  # Floor at 50 for visibility

        # ── Similarity search (Tanimoto) ─────────────────────────────────
        elif search_type == "similar":
            prod_fp  = gen.GetFingerprint(prod_mol)
            tanimoto = DataStructs.TanimotoSimilarity(query_fp, prod_fp)
            if tanimoto >= threshold:
                matched = True
                score   = round(tanimoto * 100, 1)

        if matched:
            results.append({
                "id":        prod_id,
                "score":     score,
                "canonical": prod_canonical,
            })

    # ── Sort by score descending ────────────────────────────────────────────
    results.sort(key=lambda r: r["score"], reverse=True)

    # ── Output ──────────────────────────────────────────────────────────────
    output = {
        "results":         results,
        "errors":          errors,
        "query_canonical": query_canonical,
        "search_type":     search_type,
        "threshold":       threshold,
        "total":           len(results),
    }

    sys.stdout.write(json.dumps(output, ensure_ascii=False))


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        # Catch-all for unexpected errors
        sys.stdout.write(json.dumps({
            "results": [],
            "errors": [f"Unexpected error: {e}"],
            "total": 0
        }, ensure_ascii=False))