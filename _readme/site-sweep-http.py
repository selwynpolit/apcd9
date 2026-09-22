#!/usr/bin/env python3
"""Anonymous HTTP sweep of the APC site. See _readme/anonymous-site-sweep.md.

Usage:  python3 _readme/site-sweep-http.py [base-url]
        (default https://apc3.ddev.site; use https://www.austinprogressivecalendar.com
        to sweep production -- read-only GETs/HEADs only, no forms are submitted)

Plain HTTP requests carry no cookies, so this is always an anonymous view.
Checks, for every page in the sitemap plus EXTRA_PATHS:
  - status code (anything but 200 is listed, except EXPECTED_STATUS)
  - PHP/Drupal error text leaking into the HTML
  - every same-site <a href> and <img src>/<source srcset> found on those pages
    (status of each, listed if >= 400)
  - pages with no <title>, images with no alt attribute, a missing viewport meta
Exit code 1 if anything was listed.
"""
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor

BASE = (sys.argv[1] if len(sys.argv) > 1 else "https://apc3.ddev.site").rstrip("/")

# Pages worth hitting even if the sitemap omits them.
EXTRA_PATHS = [
    "/", "/calendar", "/calendar/upcoming", "/photos", "/contact", "/blog",
    "/user/login", "/node/add/calendar_event", "/node/add/community_photo",
    "/rss.xml", "/sitemap.xml", "/robots.txt",
]

# Path prefixes whose links are skipped (actions, admin, or not meant to be GET-checked).
SKIP_PREFIXES = ("/user/logout", "/admin", "/node/add", "/batch", "/cron", "/contextual")
SKIP_CONTAINS = ("/edit", "/delete", "/publish", "/unpublish", "destination=")

# Anonymous users are *supposed* to get these.
EXPECTED_STATUS = {}

ERROR_PATTERNS = [
    r"The website encountered an unexpected error",
    r"(Warning|Notice|Deprecated): [^<]{0,300} in /[^ <]+ on line \d+", r"Fatal error",
    r"Uncaught (Exception|Error|TypeError)", r"Twig\\Error", r"Stack trace:",
    r"Undefined (variable|array key|index|property)",
]

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE


def fetch(url, method="GET"):
    req = urllib.request.Request(url, method=method, headers={"User-Agent": "apc-site-sweep"})
    try:
        with urllib.request.urlopen(req, context=ctx, timeout=30) as resp:
            body = resp.read().decode("utf-8", "replace") if method == "GET" else ""
            return resp.status, body, resp.geturl()
    except urllib.error.HTTPError as err:
        body = err.read().decode("utf-8", "replace") if method == "GET" else ""
        return err.code, body, url
    except Exception as err:  # noqa: BLE001 - report any transport failure
        return 0, str(err), url


def internal_path(raw, page_url):
    """Return a site-relative path for same-site links, else None."""
    raw = raw.strip().replace("&amp;", "&")
    if not raw or raw.startswith(("#", "mailto:", "tel:", "javascript:", "data:")):
        return None
    absolute = urllib.parse.urljoin(page_url, raw)
    parts = urllib.parse.urlsplit(absolute)
    if parts.netloc and parts.netloc != urllib.parse.urlsplit(BASE).netloc:
        return None
    path = parts.path + (("?" + parts.query) if parts.query else "")
    if path.startswith(SKIP_PREFIXES) or any(s in path for s in SKIP_CONTAINS):
        return None
    return path


def main():
    problems = []

    status, sitemap, _ = fetch(BASE + "/sitemap.xml")
    paths = list(EXTRA_PATHS)
    if status == 200:
        for loc in re.findall(r"<loc>([^<]+)</loc>", sitemap):
            path = urllib.parse.urlsplit(loc).path or "/"
            if path not in paths:
                paths.append(path)
    else:
        problems.append(("sitemap", f"/sitemap.xml returned {status}"))

    print(f"Sweeping {len(paths)} pages on {BASE} ...")
    with ThreadPoolExecutor(max_workers=8) as pool:
        pages = list(pool.map(lambda p: (p, *fetch(BASE + p)), paths))

    linked = {}  # path -> first page that linked to it
    for path, code, body, final in pages:
        if code != EXPECTED_STATUS.get(path, 200):
            problems.append((path, f"status {code}"))
            continue
        if path.endswith((".xml", ".txt")):
            continue
        for pattern in ERROR_PATTERNS:
            match = re.search(pattern, body)
            if match:
                problems.append((path, f"error text in HTML: {match.group(0)!r}"))
                break
        if not re.search(r"<title>[^<]+</title>", body):
            problems.append((path, "missing <title>"))
        if 'name="viewport"' not in body:
            problems.append((path, "missing viewport meta"))
        for tag in re.findall(r"<img\b[^>]*>", body):
            if not re.search(r"\balt=", tag):
                src = re.search(r'src="([^"]*)"', tag)
                problems.append((path, f"<img> without alt: {src.group(1)[:80] if src else tag[:80]}"))
        targets = re.findall(r'<a\b[^>]*\bhref="([^"]*)"', body)
        targets += re.findall(r'<img\b[^>]*\bsrc="([^"]*)"', body)
        for srcset in re.findall(r'\bsrcset="([^"]*)"', body):
            targets += [part.strip().split(" ")[0] for part in srcset.split(",")]
        for raw in targets:
            target = internal_path(raw, BASE + path)
            if target and target not in linked and target not in paths:
                linked[target] = path

    print(f"Checking {len(linked)} distinct linked URLs ...")

    def head(item):
        target, source = item
        code, _, _ = fetch(BASE + target, "HEAD")
        if code in (0, 405, 501):  # some handlers reject HEAD; retry as GET
            code, _, _ = fetch(BASE + target, "GET")
        return target, source, code

    with ThreadPoolExecutor(max_workers=8) as pool:
        for target, source, code in pool.map(head, linked.items()):
            if code >= 400 or code == 0:
                problems.append((source, f"links to {target} -> {code or 'no response'}"))

    alt_missing = sorted({(w, t) for w, t in problems if t.startswith("<img> without alt")})
    real = sorted({(w, t) for w, t in problems if not t.startswith("<img> without alt")})

    if real:
        # The same issue (a dead legacy link) can show up on many pages; group.
        grouped = {}
        for where, what in real:
            grouped.setdefault(what, []).append(where)
        print(f"\nPROBLEMS: {len(grouped)} distinct, across {len({w for w, _ in real})} page(s)")
        for what, wheres in sorted(grouped.items(), key=lambda kv: (-len(kv[1]), kv[0])):
            shown = ", ".join(sorted(wheres)[:3]) + (f" (+{len(wheres) - 3} more)" if len(wheres) > 3 else "")
            print(f"  [{len(wheres):>2}] {what}\n       on: {shown}")

    if alt_missing:
        # Accessibility advisory, collapsed: the footer badge repeats on every
        # page and legacy blog posts carry lots of inline images.
        by_image = {}
        for where, what in alt_missing:
            by_image.setdefault(what.split(": ", 1)[1], set()).add(where)
        repeated = {i: w for i, w in by_image.items() if len(w) > 5}
        rest = {i: w for i, w in by_image.items() if len(w) <= 5}
        print(f"\nADVISORY (accessibility): {len(by_image)} distinct image(s) with no alt attribute")
        for image, wheres in sorted(repeated.items()):
            print(f"  [{len(wheres):>2} pages] {image}   <- site-wide, fix once in the template/block")
        if rest:
            pages_with = len({w for ws in rest.values() for w in ws})
            print(f"  [+{len(rest)} content images across {pages_with} pages, mostly legacy blog posts]")
        if not real:
            return 0
        return 1
    if real:
        return 1
    print("\nNo problems found.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
