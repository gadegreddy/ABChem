import sys
import json
import os

try:
    from rdkit import Chem
    from rdkit.Chem import Draw, AllChem, Descriptors
    from rdkit.Chem.Draw import rdMolDraw2D
except ImportError as e:
    print(json.dumps({"error": f"RDKit not available: {str(e)}"}))
    sys.exit(0)

def generate_image(smiles, output_path, width=800, height=600, bond_thickness=3.0, show_legend=True, bg_color="white"):
    mol = Chem.MolFromSmiles(smiles)
    if mol is None:
        print(json.dumps({"error": "Invalid SMILES: " + smiles}))
        return False
    
    AllChem.Compute2DCoords(mol)
    
    # Create drawer
    drawer = rdMolDraw2D.MolDraw2DCairo(width, height)
    opts = drawer.drawOptions()
    
    # Bond styling
    opts.bondLineWidth = bond_thickness
    opts.doubleBondOffset = 0.3
    opts.fixedBondLength = 35
    opts.atomLabelFontSize = 28
    opts.padding = 0.12
    
    # Color palette
    opts.atomColourPalette = {
        6: (0.15, 0.15, 0.15),   # Carbon - dark gray
        7: (0.0, 0.0, 0.8),      # Nitrogen - blue
        8: (0.8, 0.0, 0.0),      # Oxygen - red
        9: (0.0, 0.7, 0.0),      # Fluorine - green
        16: (0.8, 0.6, 0.0),     # Sulfur - gold
        17: (0.0, 0.7, 0.0),     # Chlorine - green
        35: (0.6, 0.2, 0.0),     # Bromine - brown
        53: (0.6, 0.0, 0.6),     # Iodine - purple
        15: (1.0, 0.5, 0.0),     # Phosphorus - orange
    }
    
    # Background
    if bg_color == "transparent":
        opts.backgroundColour = (1.0, 1.0, 1.0, 0.0)
    elif bg_color == "light":
        opts.backgroundColour = (0.95, 0.95, 0.97)
    else:
        opts.backgroundColour = (1.0, 1.0, 1.0)
    
    drawer.DrawMolecule(mol)
    
    # Add legend
    if show_legend:
        formula = Chem.rdMolDescriptors.CalcMolFormula(mol)
        mw = Descriptors.MolWt(mol)
        legend = f"Formula: {formula}  |  MW: {mw:.2f} g/mol"
        
        legend_y = height - 40
        drawer.DrawString(legend, (20, legend_y), fontSize=16, colour=(0.3, 0.3, 0.3))
    
    drawer.FinishDrawing()
    
    with open(output_path, 'wb') as f:
        f.write(drawer.GetDrawingText())
    
    return True

# Read input
raw = sys.stdin.read().strip()
params = json.loads(raw) if raw else {}

smiles = params.get("smiles", "")
output_path = params.get("output_path", "/tmp/test.png")
width = params.get("width", 800)
height = params.get("height", 600)
bond_thickness = params.get("bond_thickness", 3.0)
show_legend = params.get("show_legend", True)
bg_color = params.get("bg_color", "white")

if not smiles:
    print(json.dumps({"error": "No SMILES provided"}))
    sys.exit(0)

success = generate_image(smiles, output_path, width, height, bond_thickness, show_legend, bg_color)

print(json.dumps({
    "success": success,
    "output_path": output_path,
    "smiles": smiles
}))