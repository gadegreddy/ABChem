<?php
/**
 * api_stereo.php — Stereo-SMILES management API for AB Chem India
 *
 * Handles AJAX calls from the admin panel to check, fetch, and manage
 * stereochemical SMILES data for the compounds catalogue.
 *
 * Depends on:
 *   - rdkit_search.py  (action=stereo_check  via check_batch)
 *   - stereo_fetch.py  (FDA GSRS + ChEMBL    via fetch_batch)
 *
 * All actions require an active admin session.
 * All input/output is JSON.  No user data ever reaches a shell argument.
 */

// ── Auth guard ────────────────────────────────────────────────────────────────
// functions.php already calls session_start() with secure cookie settings,
// so we must NOT call session_start() here; it is handled by the require below.

require_once __DIR__ . '/../private/functions.php';

if (!isset($_SESSION['role']) || !checkRole('Admin')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Shared setup ──────────────────────────────────────────────────────────────
ini_set('display_errors', 0);
ini_set('log_errors',     1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120); // fetch_batch talks to external APIs — give it room

$db     = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Route ─────────────────────────────────────────────────────────────────────
try {
    switch ($action) {

        // ────────────────────────────────────────────────────────────────────
        // GET action=stats
        // Returns stereo coverage counts for active compounds.
        // ────────────────────────────────────────────────────────────────────
        case 'stats':
            $row = $db->fetchOne(
                "SELECT
                    COUNT(*)                                                 AS total,
                    SUM(CASE WHEN stereo_status IS NULL          THEN 1 ELSE 0 END) AS unchecked,
                    SUM(CASE WHEN stereo_status = 'achiral'      THEN 1 ELSE 0 END) AS achiral,
                    SUM(CASE WHEN stereo_status = 'unverified'   THEN 1 ELSE 0 END) AS unverified,
                    SUM(CASE WHEN stereo_status = 'verified'     THEN 1 ELSE 0 END) AS verified,
                    SUM(CASE WHEN stereo_status = 'manual_review'THEN 1 ELSE 0 END) AS manual_review
                 FROM compounds
                 WHERE status = 'Active'"
            );
            echo json_encode(['success' => true, 'stats' => $row],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        // ────────────────────────────────────────────────────────────────────
        // GET action=check_batch
        // Runs RDKit stereo_check on up to 100 compounds that have SMILES
        // but no stereo_status yet.  Updates compounds.stereo_status.
        // ────────────────────────────────────────────────────────────────────
        case 'check_batch':
            $compounds = $db->fetchAll(
                "SELECT id, smiles, inchi_key
                   FROM compounds
                  WHERE status         = 'Active'
                    AND smiles         IS NOT NULL
                    AND smiles         NOT IN ('', 'NA')
                    AND stereo_status  IS NULL
                  LIMIT 100"
            );

            if (empty($compounds)) {
                echo json_encode([
                    'success'   => true,
                    'processed' => 0,
                    'achiral'   => 0,
                    'unverified'=> 0,
                    'errors'    => [],
                    'message'   => 'No compounds pending stereo check',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            // Build payload for rdkit_search.py (action: stereo_check)
            $payload = [
                'action'    => 'stereo_check',
                'compounds' => array_map(fn($c) => [
                    'id'       => (int) $c['id'],
                    'smiles'   => $c['smiles'],
                    'inchi_key'=> $c['inchi_key'] ?? '',
                ], $compounds),
            ];

            $rdkitResult = callPython(__DIR__ . '/rdkit_search.py', $payload);

            if (isset($rdkitResult['error'])) {
                http_response_code(500);
                echo json_encode(['error' => 'RDKit call failed: ' . $rdkitResult['error']]);
                break;
            }

            $processed  = 0;
            $achiral    = 0;
            $unverified = 0;
            $batchErrors= [];

            foreach ($rdkitResult['results'] ?? [] as $res) {
                $id = (int) ($res['id'] ?? 0);
                if ($id <= 0) continue;

                // rdkit_search.py stereo_check returns:
                //   stereo_status: "achiral" | "unverified"
                //   (unverified = has stereocenters but no fully-defined SMILES yet)
                $status = $res['stereo_status'] ?? null;
                if (!in_array($status, ['achiral', 'unverified'], true)) {
                    $batchErrors[] = "ID $id: unexpected stereo_status '$status'";
                    continue;
                }

                $db->query(
                    "UPDATE compounds
                        SET stereo_status = :s, updated_at = NOW()
                      WHERE id = :id",
                    [':s' => $status, ':id' => $id]
                );
                $processed++;
                if ($status === 'achiral')    $achiral++;
                if ($status === 'unverified') $unverified++;
            }

            // Propagate any Python-side errors
            foreach ($rdkitResult['errors'] ?? [] as $e) {
                $batchErrors[] = $e;
            }

            echo json_encode([
                'success'   => true,
                'processed' => $processed,
                'achiral'   => $achiral,
                'unverified'=> $unverified,
                'errors'    => $batchErrors,
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ────────────────────────────────────────────────────────────────────
        // GET action=fetch_batch
        // Queries FDA GSRS + ChEMBL for up to 20 'unverified' compounds
        // that are missing a stereo SMILES.  Updates smiles_stereo,
        // stereo_source, and stereo_status.
        // ────────────────────────────────────────────────────────────────────
        case 'fetch_batch':
            $compounds = $db->fetchAll(
                "SELECT id, compound_name, cas_number, inchi_key, smiles
                   FROM compounds
                  WHERE stereo_status = 'unverified'
                    AND (smiles_stereo IS NULL OR smiles_stereo = '')
                  LIMIT 20"
            );

            if (empty($compounds)) {
                echo json_encode([
                    'success'      => true,
                    'processed'    => 0,
                    'verified'     => 0,
                    'manual_review'=> 0,
                    'partial'      => 0,
                    'message'      => 'No unverified compounds pending stereo fetch',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $payload = [
                'compounds' => array_map(fn($c) => [
                    'id'       => (int) $c['id'],
                    'name'     => $c['compound_name'] ?? '',
                    'cas'      => $c['cas_number']    ?? '',
                    'inchi_key'=> $c['inchi_key']     ?? '',
                    'smiles'   => $c['smiles']        ?? '',
                ], $compounds),
            ];

            $fetchResult = callPython(__DIR__ . '/stereo_fetch.py', $payload);

            if (isset($fetchResult['error'])) {
                http_response_code(500);
                echo json_encode(['error' => 'stereo_fetch.py call failed: ' . $fetchResult['error']]);
                break;
            }

            $processed     = 0;
            $achiral       = 0;
            $verified      = 0;
            $manualReview  = 0;
            $partial       = 0; // found but score < 0.8 (kept as unverified)
            $batchErrors   = [];

            foreach ($fetchResult['results'] ?? [] as $res) {
                $id           = (int)   ($res['id']             ?? 0);
                $found        = (bool)  ($res['found']          ?? false);
                $score        = (float) ($res['score']          ?? 0.0);
                $totalCenters = (int)   ($res['total_centers']  ?? -1);
                if ($id <= 0) continue;

                if ($found && $totalCenters === 0) {
                    // Achiral: external source confirmed no stereocenters
                    // No stereo SMILES needed — just record it's achiral
                    $db->query(
                        "UPDATE compounds
                            SET stereo_status  = 'achiral',
                                smiles_stereo  = NULL,
                                stereo_source  = :src,
                                updated_at     = NOW()
                          WHERE id = :id",
                        [':src' => $res['stereo_source'], ':id' => $id]
                    );
                    $achiral++;

                } elseif ($found && $score >= 0.8) {
                    // High-confidence stereo SMILES → mark verified
                    $db->query(
                        "UPDATE compounds
                            SET smiles_stereo   = :smiles,
                                stereo_source   = :src,
                                stereo_status   = 'verified',
                                updated_at      = NOW()
                          WHERE id = :id",
                        [
                            ':smiles' => $res['smiles_stereo'],
                            ':src'    => $res['stereo_source'],
                            ':id'     => $id,
                        ]
                    );
                    $verified++;

                } elseif ($found && $score < 0.8) {
                    // Partial stereo SMILES — store it but leave status unverified
                    $db->query(
                        "UPDATE compounds
                            SET smiles_stereo   = :smiles,
                                stereo_source   = :src,
                                updated_at      = NOW()
                          WHERE id = :id",
                        [
                            ':smiles' => $res['smiles_stereo'],
                            ':src'    => $res['stereo_source'],
                            ':id'     => $id,
                        ]
                    );
                    $partial++;

                } else {
                    // Not found in any external source → flag for manual review
                    $db->query(
                        "UPDATE compounds
                            SET stereo_status = 'manual_review',
                                updated_at    = NOW()
                          WHERE id = :id",
                        [':id' => $id]
                    );
                    $manualReview++;
                }

                $processed++;

                // Collect per-compound errors from Python
                foreach ($res['errors'] ?? [] as $e) {
                    $batchErrors[] = "ID $id: $e";
                }
            }

            // Propagate top-level errors from Python
            foreach ($fetchResult['errors'] ?? [] as $e) {
                $batchErrors[] = $e;
            }

            echo json_encode([
                'success'      => true,
                'processed'    => $processed,
                'achiral'      => $achiral,
                'verified'     => $verified,
                'manual_review'=> $manualReview,
                'partial'      => $partial,
                'errors'       => $batchErrors,
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ────────────────────────────────────────────────────────────────────
        // POST action=update_one
        // Admin manually sets the stereo data for a single compound.
        // ────────────────────────────────────────────────────────────────────
        case 'update_one':
            // Accept either JSON body or form-encoded POST
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $post = array_merge($_POST, $body);

            $id            = filter_var($post['id']            ?? '', FILTER_VALIDATE_INT);
            $smilesStereo  = trim($post['smiles_stereo']  ?? '');
            $stereoSource  = trim($post['stereo_source']  ?? '');
            $stereoStatus  = trim($post['stereo_status']  ?? '');

            $validStatuses = ['achiral', 'unverified', 'verified', 'manual_review'];

            if ($id === false || $id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid or missing id']);
                break;
            }
            if ($smilesStereo === '') {
                http_response_code(400);
                echo json_encode(['error' => 'smiles_stereo is required']);
                break;
            }
            if ($stereoSource === '') {
                http_response_code(400);
                echo json_encode(['error' => 'stereo_source is required']);
                break;
            }
            if (!in_array($stereoStatus, $validStatuses, true)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'stereo_status must be one of: ' . implode(', ', $validStatuses),
                ]);
                break;
            }

            $db->query(
                "UPDATE compounds
                    SET smiles_stereo  = :smiles,
                        stereo_source  = :src,
                        stereo_status  = :status,
                        updated_at     = NOW()
                  WHERE id = :id",
                [
                    ':smiles' => $smilesStereo ?: null,
                    ':src'    => $stereoSource  ?: null,
                    ':status' => $stereoStatus,
                    ':id'     => $id,
                ]
            );
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        // ────────────────────────────────────────────────────────────────────
        // POST action=reset_one
        // Clears all stereo fields for a compound so it can be re-checked.
        // ────────────────────────────────────────────────────────────────────
        case 'reset_one':
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $post = array_merge($_POST, $body);
            $id = filter_var($post['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid or missing id']);
                break;
            }
            $db->query(
                "UPDATE compounds SET stereo_status=NULL, smiles_stereo=NULL, stereo_source=NULL, updated_at=NOW() WHERE id=:id",
                [':id' => $id]
            );
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        // ────────────────────────────────────────────────────────────────────
        // GET action=check_one  — single compound RDKit stereo check
        // GET action=fetch_one  — single compound external DB fetch
        // Used by "Run RDKit Check" / "Fetch from GSRS/ChEMBL" buttons on edit form
        // ────────────────────────────────────────────────────────────────────
        case 'check_one':
            $id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid id']); break; }
            $row = $db->fetchOne("SELECT id, smiles, inchi_key FROM compounds WHERE id=:id", [':id'=>$id]);
            if (!$row) { http_response_code(404); echo json_encode(['error'=>'Not found']); break; }
            if (empty($row['smiles']) || $row['smiles'] === 'NA') {
                echo json_encode(['stereo_status'=>null,'message'=>'No SMILES — cannot check']); break;
            }
            $r = callPython(__DIR__ . '/rdkit_search.py', ['action'=>'stereo_check','compounds'=>[['id'=>$id,'smiles'=>$row['smiles'],'inchi_key'=>$row['inchi_key']]]]);
            $res = $r['results'][0] ?? null;
            if ($res && isset($res['stereo_status'])) {
                $db->query("UPDATE compounds SET stereo_status=:s, updated_at=NOW() WHERE id=:id", [':s'=>$res['stereo_status'],':id'=>$id]);
                echo json_encode(['success'=>true,'stereo_status'=>$res['stereo_status'],'detail'=>$res['detail']??'','chiral_centers'=>$res['chiral_centers']??0,'defined_centers'=>$res['defined_centers']??0], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['error'=>'RDKit check failed','raw'=>$r]);
            }
            break;

        case 'fetch_one':
            $id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid id']); break; }
            $row = $db->fetchOne("SELECT id, compound_name, cas_number, inchi_key, smiles FROM compounds WHERE id=:id", [':id'=>$id]);
            if (!$row) { http_response_code(404); echo json_encode(['error'=>'Not found']); break; }
            $r = callPython(__DIR__ . '/stereo_fetch.py', ['compounds'=>[['id'=>$id,'name'=>$row['compound_name'],'cas'=>$row['cas_number'],'inchi_key'=>$row['inchi_key'],'smiles'=>$row['smiles']]]]);
            $res = $r['results'][0] ?? null;
            if ($res && !empty($res['found'])) {
                $totalCenters = (int) ($res['total_centers'] ?? -1);
                $score        = (float) ($res['score'] ?? 0.0);
                if ($totalCenters === 0) {
                    // Achiral — no stereocenters confirmed by external source
                    $db->query(
                        "UPDATE compounds SET stereo_status='achiral', smiles_stereo=NULL, stereo_source=:src, updated_at=NOW() WHERE id=:id",
                        [':src' => $res['stereo_source'], ':id' => $id]
                    );
                    echo json_encode(array_merge(['success'=>true,'stereo_status'=>'achiral'], $res), JSON_UNESCAPED_UNICODE);
                } elseif (!empty($res['smiles_stereo'])) {
                    $newStatus = ($score >= 0.8) ? 'verified' : 'unverified';
                    $db->query(
                        "UPDATE compounds SET smiles_stereo=:sm, stereo_source=:src, stereo_status=:st, updated_at=NOW() WHERE id=:id",
                        [':sm'=>$res['smiles_stereo'],':src'=>$res['stereo_source'],':st'=>$newStatus,':id'=>$id]
                    );
                    echo json_encode(array_merge(['success'=>true,'stereo_status'=>$newStatus], $res), JSON_UNESCAPED_UNICODE);
                } else {
                    $db->query("UPDATE compounds SET stereo_status='manual_review', updated_at=NOW() WHERE id=:id", [':id'=>$id]);
                    echo json_encode(['success'=>true,'found'=>false,'stereo_status'=>'manual_review','message'=>'Not found in GSRS or ChEMBL — marked for manual review']);
                }
            } else {
                $db->query("UPDATE compounds SET stereo_status='manual_review', updated_at=NOW() WHERE id=:id", [':id'=>$id]);
                echo json_encode(['success'=>true,'found'=>false,'stereo_status'=>'manual_review','message'=>'Not found in GSRS or ChEMBL — marked for manual review']);
            }
            break;

        // ────────────────────────────────────────────────────────────────────
        // Unknown action
        // ────────────────────────────────────────────────────────────────────
        default:
            http_response_code(400);
            echo json_encode([
                'error'           => 'Unknown action',
                'valid_actions'   => ['stats','check_batch','fetch_batch','check_one','fetch_one','update_one','reset_one'],
            ]);
            break;
    }
} catch (Throwable $e) {
    error_log('api_stereo.php exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}


// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Call a Python script via proc_open, passing $payload as JSON on stdin,
 * and returning the decoded JSON from stdout.
 *
 * Uses an array command form (no shell string interpolation) so no user
 * data can ever escape into a shell argument.
 *
 * @param  string $script   Absolute path to the .py file.
 * @param  array  $payload  Data to JSON-encode and send to stdin.
 * @return array            Decoded response, or ['error' => '...'] on failure.
 */
function callPython(string $script, array $payload): array
{
    if (!file_exists($script)) {
        error_log("callPython: script not found: $script");
        return ['error' => "Script not found: $script"];
    }

    $desc = [
        0 => ['pipe', 'r'],  // stdin  → we write JSON payload
        1 => ['pipe', 'w'],  // stdout ← Python writes JSON result
        2 => ['pipe', 'w'],  // stderr ← Python warnings/errors (logged)
    ];

    // Array form avoids any shell escaping / injection risk
    $proc = proc_open(['python3', $script], $desc, $pipes);
    if (!is_resource($proc)) {
        error_log("callPython: proc_open failed for $script");
        return ['error' => 'Cannot start Python process'];
    }

    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    // Log meaningful stderr output (filter out RDKit deprecation noise)
    if (!empty($stderr)) {
        $lines = array_filter(
            explode("\n", $stderr),
            fn($l) => $l !== '' && !preg_match('/(?:DEPRECATION|WARNING|DeprecationWarning)/i', $l)
        );
        if (!empty($lines)) {
            error_log(basename($script) . ' stderr: ' . implode(' | ', $lines));
        }
    }

    if (empty($stdout)) {
        error_log("callPython: empty stdout from $script (exit=$exitCode)");
        $stderrSnip = substr(trim($stderr), 0, 300);
        return ['error' => "Empty output from Python (exit=$exitCode). Stderr: $stderrSnip"];
    }

    $result = json_decode($stdout, true);
    if (!is_array($result)) {
        error_log("callPython: JSON decode failed for output of $script");
        return ['error' => 'JSON parse failed. Stderr: ' . substr($stderr, 0, 300)];
    }

    return $result;
}
