<?php
/**
 * Compound dedup helpers — shared between admin_dedup.php (manual UI)
 * and pubchem_fetch.php (auto-merge on InChIKey collision).
 *
 * Why shared:
 *   The merge is a multi-table transactional operation (supplier_listings,
 *   order_items, quote_items, saved_products, recently_viewed,
 *   pharmacopeia_impurities, compound_archive, compound_redirects, audit).
 *   We must NOT have two copies of this logic drifting apart.
 *
 * Auto-merge rule (called from pubchem_fetch.php):
 *   Triggered ONLY by InChIKey equality, never by CAS alone. An InChIKey
 *   identifies a single molecular structure unambiguously, so two rows
 *   with the same InChIKey are by definition the same compound — safe to
 *   merge automatically. CAS collisions are surfaced to the admin instead
 *   because two distinct molecules can briefly share a CAS during data
 *   entry (typo, hydrate vs anhydrate, salt form), and a wrong auto-merge
 *   destroys data.
 *
 *   Keeper selection: higher dedup_completeness_score wins; tie-break on
 *   lower id (older row). The pubchem_fetch caller does NOT assume the
 *   target compound it was fetching for is the keeper — it may end up the
 *   loser, in which case the fetch result points at the new keeper id.
 */

require_once __DIR__ . '/functions.php';

// Score completeness for the auto-suggested keeper
function dedup_completeness_score(array $row): int {
    $score = 0;
    foreach (['smiles', 'inchi', 'inchi_key', 'iupac_name',
              'molecular_formula', 'molecular_weight',
              'image_url', 'pubchem_cid'] as $f) {
        if (!empty($row[$f]) && $row[$f] !== 'NA') $score += 10;
    }
    if (!empty($row['synonyms']) && $row['synonyms'] !== 'NA') {
        $score += (int) min(strlen($row['synonyms']) / 10, 30);
    }
    $score += (int) min(($row['listings_count'] ?? 0) * 5, 50);
    $score += (int) min(($row['orders_count']   ?? 0) * 3, 30);
    return $score;
}

/**
 * Merge all $loserIds into $keeperId in a single DB transaction.
 *
 * Returns the keeper row id on success; throws Exception on failure
 * (caller is responsible for rolling its own try/catch around bulk loops).
 */
function dedup_merge(Database $db, int $keeperId, array $loserIds, string $reason): int {
    $loserIds = array_values(array_unique(array_map('intval', $loserIds)));
    $loserIds = array_filter($loserIds, fn($id) => $id > 0 && $id !== $keeperId);
    if (empty($loserIds)) throw new Exception("No valid loser ids supplied for keeper $keeperId");

    $pdo = $db->getConnection();
    $pdo->beginTransaction();

    try {
        $keeper = $db->fetchOne("SELECT * FROM compounds WHERE id = :id", ['id' => $keeperId]);
        if (!$keeper) throw new Exception("Keeper compound #$keeperId not found");

        $mergedBy     = $_SESSION['email'] ?? ($_SESSION['user_email'] ?? 'admin');
        $updateKeeper = [];

        // Fields where keeper's empty value should be filled from loser
        $fillFields = ['smiles', 'inchi', 'inchi_key', 'iupac_name', 'molecular_formula',
                       'molecular_weight', 'image_url', 'pubchem_cid', 'cas_number',
                       'smiles_canonical', 'smiles_stereo'];

        foreach ($loserIds as $loserId) {
            $loser = $db->fetchOne("SELECT * FROM compounds WHERE id = :id", ['id' => $loserId]);
            if (!$loser) continue; // already gone

            $changes = [];

            // 1. Field merge — fill keeper's empty fields from loser
            foreach ($fillFields as $f) {
                $keeperEmpty = empty($keeper[$f]) || $keeper[$f] === 'NA';
                $loserHas    = !empty($loser[$f]) && $loser[$f] !== 'NA';
                if ($keeperEmpty && $loserHas) {
                    $updateKeeper[$f] = $loser[$f];
                    $keeper[$f]       = $loser[$f]; // so the next loser sees the merged state
                    $changes[$f]      = $loser[$f];
                }
            }

            // 2. Synonyms — UNION + dedupe + cap 50, plus add loser's compound_name
            $keepSyns  = !empty($keeper['synonyms']) && $keeper['synonyms'] !== 'NA'
                ? explode('|', $keeper['synonyms']) : [];
            $loserSyns = !empty($loser['synonyms']) && $loser['synonyms'] !== 'NA'
                ? explode('|', $loser['synonyms']) : [];
            if (!empty($loser['compound_name'])
                && strcasecmp($loser['compound_name'], $keeper['compound_name']) !== 0) {
                $loserSyns[] = $loser['compound_name'];
            }
            $merged = array_values(array_unique(array_filter(
                array_map('trim', array_merge($keepSyns, $loserSyns)),
                fn($s) => $s !== '' && $s !== 'NA' && strlen($s) > 1
            )));
            if (!empty($merged)) {
                $newSyn = implode('|', array_slice($merged, 0, 50));
                if ($newSyn !== ($keeper['synonyms'] ?? '')) {
                    $updateKeeper['synonyms'] = $newSyn;
                    $keeper['synonyms']       = $newSyn;
                    $changes['synonyms']      = $newSyn;
                }
            }

            // 3. Snapshot listings for archive BEFORE transferring
            $listings = $db->fetchAll(
                "SELECT * FROM supplier_listings WHERE compound_id = :id",
                ['id' => $loserId]
            );

            // 4. Transfer supplier listings (keep-both rule — see admin_dedup design notes)
            $db->query(
                "UPDATE supplier_listings SET compound_id = :keeper WHERE compound_id = :loser",
                ['keeper' => $keeperId, 'loser' => $loserId]
            );

            // 5. Transfer other FK references. Each wrapped because the table or
            //    column may not exist in older schemas; we soft-fail on each.
            foreach (['order_items', 'quote_items', 'saved_products',
                      'recently_viewed', 'pharmacopeia_impurities'] as $table) {
                try {
                    $db->query(
                        "UPDATE `$table` SET compound_id = :keeper WHERE compound_id = :loser",
                        ['keeper' => $keeperId, 'loser' => $loserId]
                    );
                } catch (Exception $e) {
                    error_log("[dedup] $table transfer skipped: " . $e->getMessage());
                }
            }

            // 6. Archive snapshot of loser + transferred listings
            $db->insert('compound_archive', [
                'original_id'       => $loserId,
                'merged_into_id'    => $keeperId,
                'row_snapshot'      => json_encode($loser,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'listings_snapshot' => json_encode($listings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'field_changes'     => json_encode($changes,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'merge_reason'      => substr($reason, 0, 64),
                'merged_by'         => substr($mergedBy, 0, 120),
            ]);

            // 7. Redirect entries — one row covers all four legacy URL surfaces
            $db->insert('compound_redirects', [
                'old_slug'        => $loser['slug']              ?? null,
                'old_url_slug'    => $loser['url_slug']          ?? null,
                'old_ab_catalog'  => $loser['ab_catalog_number'] ?? null,
                'old_url_token'   => $loser['url_token']         ?? null,
                'new_compound_id' => $keeperId,
                'merged_from_id'  => $loserId,
                'merge_reason'    => substr($reason, 0, 64),
                'merged_by'       => substr($mergedBy, 0, 120),
            ]);

            // 8. Delete the loser row (FKs already transferred above)
            $db->query("DELETE FROM compounds WHERE id = :id", ['id' => $loserId]);

            // 9. Audit log
            logAudit(
                'compound_merged',
                "Merged compound #$loserId (\"" . ($loser['compound_name'] ?? '') . "\") into #$keeperId (\"" . ($keeper['compound_name'] ?? '') . "\") via $reason",
                json_encode(['id' => $loserId, 'name' => $loser['compound_name'] ?? '']),
                json_encode($changes)
            );
        }

        // Apply accumulated keeper updates
        if (!empty($updateKeeper)) {
            $updateKeeper['updated_at'] = date('Y-m-d H:i:s');
            $db->update('compounds', $updateKeeper, 'id = :id', ['id' => $keeperId]);
        }

        $pdo->commit();
        return $keeperId;

    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}
