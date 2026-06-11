#!/usr/bin/env python3
"""
generate_canonical_smiles.py
Reads SMILES from stdin (JSON), returns canonical SMILES via stdout (JSON).
Also accepts --batch mode to process all products from a CSV dump.

Usage:
  echo '{"smiles":["C1=CC=CC=C1","CC(C)CC1=CC=C(C=C1)C(C)C(=O)O"]}' | python3 generate_canonical_smiles.py
  
  mysql -u user -p -D db -e "SELECT id, smiles FROM products WHERE smiles IS NOT NULL AND smiles != '' AND smiles != 'NA'" | python3 generate_canonical_smiles.py --batch
"""

import sys, json, csv, io, argparse
from rdkit import Chem
from rdkit.Chem import AllChem

def smiles_to_canonical(smiles):
    """Convert any SMILES to canonical isomeric SMILES"""
    if not smiles or smiles in ('NA', '', 'None', None):
        return None
    
    try:
        mol = Chem.MolFromSmiles(str(smiles))
        if mol is None:
            return None
        
        # Generate canonical isomeric SMILES (preserves stereochemistry)
        canonical = Chem.MolToSmiles(mol, isomericSmiles=True, canonical=True)
        
        # Also try non-isomeric as fallback if stereo causes issues
        if canonical == smiles:
            canonical_noniso = Chem.MolToSmiles(mol, isomericSmiles=False, canonical=True)
            if canonical_noniso != canonical:
                canonical = canonical_noniso
        
        return canonical
    except Exception as e:
        return None


def process_batch_mode():
    """Read TSV/CSV from stdin: id\tsmiles"""
    results = []
    errors = []
    
    reader = csv.reader(sys.stdin, delimiter='\t')
    header = next(reader, None)  # Skip header row
    
    for row in reader:
        if len(row) < 2:
            continue
        
        prod_id = row[0].strip()
        smiles = row[1].strip()
        
        canonical = smiles_to_canonical(smiles)
        if canonical:
            results.append({'id': prod_id, 'canonical_smiles': canonical})
        else:
            errors.append({'id': prod_id, 'error': f'Failed to parse: {smiles[:50]}'})
    
    output = {
        'total': len(results),
        'errors': len(errors),
        'results': results
    }
    if errors:
        output['error_details'] = errors
    
    print(json.dumps(output), flush=True)


def process_single_mode():
    """Read JSON from stdin: {"smiles": ["...", "..."]}"""
    try:
        data = json.load(sys.stdin)
    except json.JSONDecodeError:
        print(json.dumps({'error': 'Invalid JSON input'}))
        sys.exit(1)
    
    smiles_list = data.get('smiles', [])
    results = []
    
    for smi in smiles_list:
        canonical = smiles_to_canonical(smi)
        results.append({
            'original': smi,
            'canonical': canonical
        })
    
    print(json.dumps({'results': results}), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--batch', action='store_true', help='Batch mode: read TSV from stdin')
    parser.add_argument('--smiles', type=str, help='Single SMILES string to convert')
    args = parser.parse_args()
    
    if args.smiles:
        # Direct single SMILES conversion
        result = smiles_to_canonical(args.smiles)
        print(json.dumps({'smiles': args.smiles, 'canonical': result}))
    elif args.batch:
        process_batch_mode()
    else:
        process_single_mode()