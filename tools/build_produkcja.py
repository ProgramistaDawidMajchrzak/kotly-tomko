"""Składa folder produkcja/ do wgrania na kotlytomko.pl (Webio, IIS).

Uruchomienie:  python tools/build_produkcja.py
- usuwa testowe noindex i atrapę formularza z GitHub Pages,
- dokleja ?v=<data> do CSS/JS (cache przeglądarek przy aktualizacjach),
- nie kopiuje plików tylko-dla-repo (.htaccess, .nojekyll, zdjęcia źródłowe itd.).
"""
import re
import shutil
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "produkcja"
VER = datetime.now().strftime("%Y%m%d%H%M")

FILES = [
    "index.html", "polityka-prywatnosci.html", "kontakt.php", "web.config",
    "robots.txt", "sitemap.xml", "favicon.svg", "favicon-32.png", "apple-touch-icon.png",
]
DIRS = ["assets"]


def must_sub(pattern, repl, text, what, flags=re.S):
    new, n = re.subn(pattern, repl, text, flags=flags)
    if n == 0:
        sys.exit(f"BŁĄD: nie znaleziono: {what}")
    return new


def main():
    if OUT.exists():
        shutil.rmtree(OUT)
    OUT.mkdir()
    for f in FILES:
        shutil.copy2(ROOT / f, OUT / f)
    for d in DIRS:
        shutil.copytree(ROOT / d, OUT / d)

    # index.html: bez noindex, wersjonowanie CSS/JS
    p = OUT / "index.html"
    t = p.read_text(encoding="utf-8")
    t = must_sub(r"\s*<!-- WERSJA TESTOWA:.*?-->\s*<meta name=\"robots\" content=\"noindex, nofollow\">",
                 "", t, "testowy noindex w index.html")
    t = must_sub(r'href="assets/css/style\.css"', f'href="assets/css/style.css?v={VER}"', t, "link do CSS")
    t = must_sub(r'src="assets/js/main\.js"', f'src="assets/js/main.js?v={VER}"', t, "skrypt main.js")
    p.write_text(t, encoding="utf-8", newline="\n")

    # polityka prywatności: ta sama wersja CSS
    p = OUT / "polityka-prywatnosci.html"
    t = p.read_text(encoding="utf-8")
    t = re.sub(r'href="assets/css/style\.css"', f'href="assets/css/style.css?v={VER}"', t)
    p.write_text(t, encoding="utf-8", newline="\n")

    # main.js: bez atrapy formularza dla github.io
    p = OUT / "assets" / "js" / "main.js"
    t = p.read_text(encoding="utf-8")
    t = must_sub(r"\n\s*// podgląd na GitHub Pages.*?\n\s*if \(location\.hostname\.endsWith\('github\.io'\)\) \{.*?\n\s*\}\n",
                 "\n", t, "atrapa formularza w main.js")
    p.write_text(t, encoding="utf-8", newline="\n")

    # kontrola: nic testowego nie zostało
    for f in OUT.rglob("*"):
        if f.suffix in {".html", ".js", ".php"}:
            s = f.read_text(encoding="utf-8")
            for bad in ("noindex, nofollow\">\n  <title>Remonty", "github.io", "WERSJA TESTOWA"):
                if bad in s and f.name != "polityka-prywatnosci.html":
                    sys.exit(f"BŁĄD: '{bad}' w {f.relative_to(OUT)}")

    size = sum(f.stat().st_size for f in OUT.rglob("*") if f.is_file())
    count = sum(1 for f in OUT.rglob("*") if f.is_file())
    print(f"OK: produkcja/ — {count} plików, {size / 1024 / 1024:.1f} MB, wersja {VER}")


if __name__ == "__main__":
    main()
