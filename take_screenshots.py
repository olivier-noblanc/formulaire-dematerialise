#!/usr/bin/env python3
"""Take screenshots of all Workflow DREETS pages for docs.php"""
import os
import sys
import time
import subprocess
import signal
from playwright.sync_api import sync_playwright

PHP_BIN = "/tmp/my-project/bin/php/bin/php"
DOCROOT = "/home/z/my-project/formulaire-dematerialise"
PORT = 8765
OUT_DIR = os.path.join(DOCROOT, "docs", "screenshots")
os.makedirs(OUT_DIR, exist_ok=True)

HEADERS = {
    "X-Test-Mode": "1",
    "X-Test-User": "olivier.noblanc@dreets.gouv.fr"
}

PAGES = [
    ("01_index_agent", "/index.php"),
    ("02_index_admin", "/index.php"),
    ("03_form_onboarding", "/form.php?f=onboarding"),
    ("04_form_outboarding", "/form.php?f=outboarding"),
    ("05_my_submissions", "/my_submissions.php"),
    ("06_my_validations", "/my_validations.php"),
    ("07_dashboard", "/dashboard.php"),
    ("08_monitoring", "/monitoring.php"),
    ("09_admin_access", "/admin_access.php"),
    ("10_admin_forms", "/admin_forms.php"),
    ("11_admin_alerts", "/admin_alerts.php"),
    ("12_admin_settings", "/admin_settings.php"),
    ("13_docs", "/docs.php"),
    ("14_changelog", "/changelog.php"),
    ("15_validate", "/my_validations.php"),
    ("16_submission_view", "/my_submissions.php"),
    ("17_form_preview", "/admin_forms.php"),
]

def take_screenshots():
    # Start PHP server
    print(f"Starting PHP server on port {PORT}...")
    php_proc = subprocess.Popen(
        [PHP_BIN, "-S", f"0.0.0.0:{PORT}", "-t", DOCROOT],
        cwd=DOCROOT,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE
    )
    time.sleep(3)
    
    # Verify server is running
    import urllib.request
    try:
        req = urllib.request.Request(f"http://127.0.0.1:{PORT}/index.php")
        req.add_header("X-Test-Mode", "1")
        req.add_header("X-Test-User", "olivier.noblanc@dreets.gouv.fr")
        r = urllib.request.urlopen(req, timeout=5)
        print(f"Server is running (HTTP {r.status})")
    except Exception as e:
        print(f"Server failed to start: {e}")
        php_proc.kill()
        sys.exit(1)
    
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True, args=["--no-sandbox", "--disable-setuid-sandbox"])
            context = browser.new_context(
                viewport={"width": 1280, "height": 900},
                extra_http_headers=HEADERS
            )
            
            for i, (name, path) in enumerate(PAGES):
                page = context.new_page()
                url = f"http://127.0.0.1:{PORT}{path}"
                print(f"[{i+1}/{len(PAGES)}] Capturing {name} -> {url}")
                
                try:
                    page.goto(url, wait_until="networkidle", timeout=20000)
                    time.sleep(1.5)
                    page.evaluate("window.scrollTo(0, 0)")
                    time.sleep(0.3)
                    
                    outfile = os.path.join(OUT_DIR, f"{name}.png")
                    page.screenshot(path=outfile, full_page=False)
                    print(f"  ✓ Saved {outfile} ({os.path.getsize(outfile)} bytes)")
                    
                except Exception as e:
                    print(f"  ✗ Error: {e}")
                
                page.close()
            
            browser.close()
    finally:
        php_proc.terminate()
        php_proc.wait(timeout=5)
        print("PHP server stopped")
    
    print(f"\nDone! All screenshots saved to {OUT_DIR}")

if __name__ == "__main__":
    take_screenshots()
