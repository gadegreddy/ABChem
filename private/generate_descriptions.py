import os
import time
import mysql.connector
from google import genai
from google.genai import types
from dotenv import load_dotenv

# Load environment variables (from the .env file in the directory above private)
env_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), '.env')
load_dotenv(env_path)

# 1. Initialize Gemini Client
# Make sure you have GEMINI_API_KEY set in your .env file
# Install via: pip install google-genai
try:
    client = genai.Client(api_key=os.environ.get("GEMINI_API_KEY"))
except Exception as e:
    print(f"Error initializing Gemini client: {e}")
    print("Please ensure GEMINI_API_KEY is set in your .env file.")
    exit(1)

# 2. Database Connection
try:
    db = mysql.connector.connect(
        host=os.environ.get("DB_HOST", "localhost"),
        user=os.environ.get("DB_USER"),
        password=os.environ.get("DB_PASS"),
        database=os.environ.get("DB_NAME")
    )
    cursor = db.cursor(dictionary=True)
except Exception as e:
    print(f"Database connection failed: {e}")
    exit(1)

def generate_description(compound):
    """
    Calls the Gemini API to generate a SEO-friendly product description.
    """
    prompt = f"""
    You are an expert chemical catalog copywriter. Write a concise, professional product description for the following chemical compound:
    
    Name: {compound['compound_name']}
    CAS Number: {compound['cas_number']}
    Molecular Formula: {compound['molecular_formula']}
    Product Type: {compound['product_type']}
    Parent Drug (if any): {compound['parent_drug']}
    
    Requirements:
    1. Keep it under 100 words.
    2. Focus on its general applications (e.g., as an API, impurity, or intermediate), physical properties, or role in pharmaceutical research.
    3. Do NOT include medical advice. Keep it strictly scientific/commercial.
    4. Provide the output as plain text without markdown formatting.
    """
    
    try:
        response = client.models.generate_content(
            model='gemini-2.5-pro', # Or gemini-2.5-flash for faster/cheaper generation
            contents=prompt,
        )
        return response.text.strip()
    except Exception as e:
        print(f"  [!] API Error for ID {compound['id']}: {e}")
        return None

def main():
    print("Starting Compound Description Generation...")
    
    # Optional: Run this SQL once in your database if you don't have a description column yet:
    # ALTER TABLE compounds ADD COLUMN description TEXT DEFAULT NULL AFTER compound_name;
    
    # Fetch compounds that need descriptions
    cursor.execute("""
        SELECT id, compound_name, cas_number, molecular_formula, product_type, parent_drug 
        FROM compounds 
        WHERE status = 'Active' 
          # Uncomment the line below once the 'description' column is added
          # AND (description IS NULL OR description = '')
        LIMIT 1000
    """)
    compounds = cursor.fetchall()
    
    print(f"Found {len(compounds)} compounds to process.")
    
    success_count = 0
    for idx, compound in enumerate(compounds, 1):
        print(f"[{idx}/{len(compounds)}] Generating description for: {compound['compound_name']} (CAS: {compound['cas_number']})")
        
        description = generate_description(compound)
        
        if description:
            # Update the database
            update_query = "UPDATE compounds SET description = %s WHERE id = %s"
            try:
                # Uncomment the lines below to actually execute the update!
                # cursor.execute(update_query, (description, compound['id']))
                # db.commit()
                success_count += 1
                print(f"  [+] Success.")
            except Exception as e:
                print(f"  [!] DB Update Error: {e}")
        
        # Respect rate limits - wait 2 seconds between requests
        time.sleep(2)
        
    print(f"\nFinished! Successfully generated and updated {success_count} descriptions.")
    cursor.close()
    db.close()

if __name__ == "__main__":
    main()
