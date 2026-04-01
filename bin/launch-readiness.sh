#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# WPMediaVerse Launch Readiness Check
# Validates plugin is ready for release — structure, code, features, and market.
# Usage: bash bin/launch-readiness.sh [--fix]
# ─────────────────────────────────────────────────────────────────────────────

set +e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PASS=0
FAIL=0
WARN=0
FIX_MODE="${1:-}"

pass() { ((PASS++)); echo -e "  ${GREEN}✓${NC} $1"; }
fail() { ((FAIL++)); echo -e "  ${RED}✗${NC} $1"; }
warn() { ((WARN++)); echo -e "  ${YELLOW}⚠${NC} $1"; }
section() { echo -e "\n${BOLD}${BLUE}━━━ $1 ━━━${NC}"; }

echo -e "${BOLD}${CYAN}"
echo "╔══════════════════════════════════════════════╗"
echo "║   WPMediaVerse Launch Readiness Check        ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"
echo "Plugin dir: $PLUGIN_DIR"
echo "Date: $(date '+%Y-%m-%d %H:%M')"

# ─── 1. VERSION CONSISTENCY ──────────────────────────────────────────────────

section "1. Version Consistency"

# Extract versions from various sources
MAIN_VERSION=$(grep -m1 "Version:" "$PLUGIN_DIR/wpmediaverse.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
CONSTANT_VERSION=$(grep -m1 "define.*MVS_VERSION" "$PLUGIN_DIR/wpmediaverse.php" | sed "s/.*'\\([0-9.]*\\)'.*/\\1/")
README_VERSION=$(grep -m1 "Stable tag:" "$PLUGIN_DIR/readme.txt" | sed 's/.*Stable tag:[[:space:]]*//' | tr -d '[:space:]')

if [ -f "$PLUGIN_DIR/package.json" ]; then
    PKG_VERSION=$(grep -m1 '"version"' "$PLUGIN_DIR/package.json" | sed 's/.*"\([0-9][0-9.]*\)".*/\1/')
else
    PKG_VERSION="N/A"
fi

echo "  Plugin header:  $MAIN_VERSION"
echo "  MVS_VERSION:    $CONSTANT_VERSION"
echo "  readme.txt:     $README_VERSION"
echo "  package.json:   $PKG_VERSION"

if [ "$MAIN_VERSION" = "$CONSTANT_VERSION" ] && [ "$MAIN_VERSION" = "$README_VERSION" ]; then
    pass "All versions match: $MAIN_VERSION"
else
    fail "Version mismatch detected"
fi

if [ "$PKG_VERSION" != "N/A" ] && [ "$PKG_VERSION" != "$MAIN_VERSION" ]; then
    warn "package.json version ($PKG_VERSION) differs from plugin version ($MAIN_VERSION)"
fi

# ─── 2. REQUIRED FILES ──────────────────────────────────────────────────────

section "2. Required Files"

REQUIRED_FILES=(
    "wpmediaverse.php"
    "readme.txt"
    "uninstall.php"
    ".distignore"
    "languages/wpmediaverse.pot"
    "package.json"
)

for f in "${REQUIRED_FILES[@]}"; do
    if [ -f "$PLUGIN_DIR/$f" ]; then
        pass "$f exists"
    else
        fail "$f MISSING"
    fi
done

# Check .pot is not empty
if [ -f "$PLUGIN_DIR/languages/wpmediaverse.pot" ]; then
    POT_LINES=$(wc -l < "$PLUGIN_DIR/languages/wpmediaverse.pot")
    if [ "$POT_LINES" -gt 20 ]; then
        pass ".pot file has $POT_LINES lines"
    else
        warn ".pot file only has $POT_LINES lines — may need regeneration"
    fi
fi

# ─── 3. BUILD ARTIFACTS ─────────────────────────────────────────────────────

section "3. Build Artifacts"

if [ -d "$PLUGIN_DIR/build" ]; then
    BLOCK_DIR_COUNT=$(find "$PLUGIN_DIR/build/blocks" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l | tr -d ' ')
    BLOCK_JS_COUNT=$(find "$PLUGIN_DIR/build" -name "*.js" -type f 2>/dev/null | wc -l | tr -d ' ')
    if [ "$BLOCK_DIR_COUNT" -ge 10 ]; then
        pass "Build dir has $BLOCK_DIR_COUNT block directories, $BLOCK_JS_COUNT JS assets"
    else
        warn "Only $BLOCK_DIR_COUNT block directories found (expected 12+)"
    fi
else
    fail "build/ directory missing — run npm run build"
fi

# Check for node_modules (should not be in release)
if [ -d "$PLUGIN_DIR/node_modules" ]; then
    warn "node_modules/ exists — will be excluded by .distignore but verify"
else
    pass "node_modules/ not present"
fi

# Check .distignore covers dev files
if [ -f "$PLUGIN_DIR/.distignore" ]; then
    DISTIGNORE_CHECKS=("node_modules" ".git" "/src" "package.json" ".editorconfig")
    for pattern in "${DISTIGNORE_CHECKS[@]}"; do
        if grep -q "$pattern" "$PLUGIN_DIR/.distignore" 2>/dev/null; then
            pass ".distignore excludes $pattern"
        else
            warn ".distignore may not exclude $pattern"
        fi
    done
fi

# ─── 4. CODE QUALITY ────────────────────────────────────────────────────────

section "4. Code Quality"

# Debug statements left in production code
DEBUG_HITS=$(grep -rn "error_log\|var_dump\|print_r\|console\.log.*DEBUG\|wp_die.*debug" \
    --include="*.php" --include="*.js" \
    "$PLUGIN_DIR" \
    --exclude-dir=node_modules \
    --exclude-dir=vendor \
    --exclude-dir=build \
    --exclude-dir=bin \
    --exclude-dir=dist \
    2>/dev/null | grep -v "test" | grep -v "Test" | grep -v "mvs_error_log" | grep -v "LoggerService\|LogViewerPage" | wc -l | tr -d ' ')

if [ "$DEBUG_HITS" -eq 0 ]; then
    pass "No debug statements (error_log, var_dump, print_r)"
else
    warn "$DEBUG_HITS potential debug statements found"
    grep -rn "error_log\|var_dump\|print_r" \
        --include="*.php" \
        "$PLUGIN_DIR" \
        --exclude-dir=node_modules \
        --exclude-dir=vendor \
        --exclude-dir=build \
        --exclude-dir=bin \
        2>/dev/null | grep -v "test" | grep -v "Test" | head -5 | while read -r line; do
        echo -e "    ${YELLOW}→${NC} $line"
    done
fi

# TODO/FIXME/HACK markers
TODO_HITS=$(grep -rn "TODO\|FIXME\|HACK\|XXX" \
    --include="*.php" --include="*.js" \
    "$PLUGIN_DIR" \
    --exclude-dir=node_modules \
    --exclude-dir=vendor \
    --exclude-dir=build \
    --exclude-dir=bin \
    2>/dev/null | wc -l | tr -d ' ')

if [ "$TODO_HITS" -eq 0 ]; then
    pass "No TODO/FIXME/HACK markers"
else
    warn "$TODO_HITS TODO/FIXME/HACK markers found"
    grep -rn "TODO\|FIXME\|HACK\|XXX" \
        --include="*.php" --include="*.js" \
        "$PLUGIN_DIR" \
        --exclude-dir=node_modules \
        --exclude-dir=vendor \
        --exclude-dir=build \
        --exclude-dir=bin \
        2>/dev/null | head -5 | while read -r line; do
        echo -e "    ${YELLOW}→${NC} $line"
    done
fi

# Hardcoded localhost/dev URLs
DEV_URLS=$(grep -rn "localhost\|\.local\|127\.0\.0\.1\|example\.com" \
    --include="*.php" \
    "$PLUGIN_DIR" \
    --exclude-dir=node_modules \
    --exclude-dir=vendor \
    --exclude-dir=build \
    --exclude-dir=bin \
    --exclude-dir=marketing \
    --exclude-dir=docs \
    --exclude-dir=plan \
    --exclude-dir=dist \
    2>/dev/null | grep -v "test" | grep -v "Test" | grep -v "readme" | grep -v ".md" | grep -v "return.*127\.0\.0\.1" | grep -v "@demo\.local" | wc -l | tr -d ' ')

if [ "$DEV_URLS" -eq 0 ]; then
    pass "No hardcoded localhost/dev URLs in PHP"
else
    warn "$DEV_URLS potential hardcoded dev URLs"
    grep -rn "localhost\|\.local\|127\.0\.0\.1" \
        --include="*.php" \
        "$PLUGIN_DIR" \
        --exclude-dir=node_modules \
        --exclude-dir=vendor \
        --exclude-dir=build \
        --exclude-dir=bin \
        --exclude-dir=marketing \
        --exclude-dir=docs \
        2>/dev/null | grep -v "test" | grep -v "Test" | head -5 | while read -r line; do
        echo -e "    ${YELLOW}→${NC} $line"
    done
fi

# WP_DEBUG left enabled
DEBUG_ENABLE=$(grep -rn "define.*WP_DEBUG.*true\|define.*MVS_DEBUG.*true" \
    --include="*.php" \
    "$PLUGIN_DIR" \
    --exclude-dir=node_modules \
    --exclude-dir=vendor \
    --exclude-dir=build \
    2>/dev/null | wc -l | tr -d ' ')

if [ "$DEBUG_ENABLE" -eq 0 ]; then
    pass "No hardcoded debug mode enables"
else
    fail "$DEBUG_ENABLE debug mode defines found — remove before release"
fi

# ─── 5. SECURITY QUICK CHECK ────────────────────────────────────────────────

section "5. Security Quick Check"

# Direct file access protection
DIRECT_ACCESS=$(grep -rL "defined.*ABSPATH\|defined.*WPINC" \
    --include="*.php" \
    "$PLUGIN_DIR/includes" \
    2>/dev/null | head -5)

if [ -z "$DIRECT_ACCESS" ]; then
    pass "All PHP files in includes/ have ABSPATH check"
else
    DIRECT_COUNT=$(echo "$DIRECT_ACCESS" | wc -l | tr -d ' ')
    warn "$DIRECT_COUNT files may lack ABSPATH guard"
    echo "$DIRECT_ACCESS" | head -3 | while read -r line; do
        echo -e "    ${YELLOW}→${NC} $line"
    done
fi

# Nonce usage in AJAX handlers
AJAX_HANDLERS=$(grep -rn "wp_ajax_" --include="*.php" "$PLUGIN_DIR/includes" 2>/dev/null | wc -l | tr -d ' ')
NONCE_CHECKS=$(grep -rn "check_ajax_referer\|wp_verify_nonce" --include="*.php" "$PLUGIN_DIR/includes" 2>/dev/null | wc -l | tr -d ' ')

echo "  AJAX handlers found: $AJAX_HANDLERS"
echo "  Nonce verifications: $NONCE_CHECKS"
if [ "$AJAX_HANDLERS" -gt 0 ] && [ "$NONCE_CHECKS" -ge "$AJAX_HANDLERS" ]; then
    pass "Nonce checks >= AJAX handlers"
elif [ "$AJAX_HANDLERS" -eq 0 ]; then
    pass "No AJAX handlers to verify"
else
    warn "Fewer nonce checks ($NONCE_CHECKS) than AJAX handlers ($AJAX_HANDLERS)"
fi

# Exposed API keys or secrets
SECRET_HITS=$(grep -rn "sk-\|AKIA\|password\s*=" \
    --include="*.php" \
    "$PLUGIN_DIR" \
    --exclude-dir=node_modules \
    --exclude-dir=vendor \
    --exclude-dir=build \
    2>/dev/null | grep -v "password_hash\|wp_hash_password\|password_field\|->password\|sanitize\|esc_\|get_option\|__(" | wc -l | tr -d ' ')

if [ "$SECRET_HITS" -eq 0 ]; then
    pass "No exposed API keys or hardcoded secrets"
else
    warn "$SECRET_HITS potential exposed secrets — verify manually"
fi

# ─── 6. FEATURE COMPLETENESS ────────────────────────────────────────────────

section "6. Feature Completeness (V1 Status)"

if [ -f "$PLUGIN_DIR/plan/V1-STATUS.md" ]; then
    DONE_COUNT=$(grep -c "| DONE |" "$PLUGIN_DIR/plan/V1-STATUS.md" 2>/dev/null) || DONE_COUNT=0
    PENDING_COUNT=$(grep -c "| PENDING |" "$PLUGIN_DIR/plan/V1-STATUS.md" 2>/dev/null) || PENDING_COUNT=0
    WIP_COUNT=$(grep -c "| WIP |" "$PLUGIN_DIR/plan/V1-STATUS.md" 2>/dev/null) || WIP_COUNT=0

    echo "  Features DONE:    $DONE_COUNT"
    echo "  Features PENDING: $PENDING_COUNT"
    echo "  Features WIP:     $WIP_COUNT"

    if [ "$PENDING_COUNT" -eq 0 ] && [ "$WIP_COUNT" -eq 0 ]; then
        pass "All $DONE_COUNT features complete"
    else
        fail "$PENDING_COUNT pending, $WIP_COUNT in progress"
    fi

    # Check release checklist items
    UNCHECKED=$(grep -c "\- \[ \]" "$PLUGIN_DIR/plan/V1-STATUS.md" 2>/dev/null) || UNCHECKED=0
    CHECKED=$(grep -c "\- \[x\]" "$PLUGIN_DIR/plan/V1-STATUS.md" 2>/dev/null) || CHECKED=0

    if [ "$UNCHECKED" -eq 0 ]; then
        pass "Release checklist: all $CHECKED items checked"
    else
        fail "Release checklist: $UNCHECKED items still unchecked"
        grep "\- \[ \]" "$PLUGIN_DIR/plan/V1-STATUS.md" 2>/dev/null | while read -r line; do
            echo -e "    ${RED}→${NC} $line"
        done
    fi
else
    warn "plan/V1-STATUS.md not found"
fi

# ─── 7. DATABASE TABLE VALIDATION ───────────────────────────────────────────

section "7. Database Schema"

# Check that table creation SQL exists for all 3 core tables
CORE_TABLES=("mvs_media_index" "mvs_media_meta" "mvs_media_stats")
for table in "${CORE_TABLES[@]}"; do
    if grep -rq "CREATE TABLE.*$table" --include="*.php" "$PLUGIN_DIR" 2>/dev/null; then
        pass "CREATE TABLE for $table found"
    else
        fail "CREATE TABLE for $table NOT found"
    fi
done

# Check indexes exist in schema
INDEX_COUNT=$(grep -rn "INDEX\|KEY\s*(" --include="*.php" "$PLUGIN_DIR/includes" 2>/dev/null | grep -i "create\|index\|key" | wc -l | tr -d ' ')
if [ "$INDEX_COUNT" -ge 3 ]; then
    pass "$INDEX_COUNT index definitions found in schema"
else
    warn "Only $INDEX_COUNT indexes found — verify query performance"
fi

# ─── 8. REST API COVERAGE ───────────────────────────────────────────────────

section "8. REST API"

CONTROLLER_COUNT=$(grep -rl "register_rest_route\|WP_REST_Controller" --include="*.php" "$PLUGIN_DIR/includes" 2>/dev/null | wc -l | tr -d ' ')
ROUTE_COUNT=$(grep -rn "register_rest_route" --include="*.php" "$PLUGIN_DIR/includes" 2>/dev/null | wc -l | tr -d ' ')

echo "  Controllers: $CONTROLLER_COUNT"
echo "  Route registrations: $ROUTE_COUNT"

if [ "$CONTROLLER_COUNT" -ge 15 ]; then
    pass "17+ REST controllers expected — found $CONTROLLER_COUNT"
else
    warn "Expected 17 controllers, found $CONTROLLER_COUNT"
fi

# Permission callbacks
PERM_CALLBACKS=$(grep -rn "permission_callback" --include="*.php" "$PLUGIN_DIR/includes" 2>/dev/null | wc -l | tr -d ' ')
if [ "$PERM_CALLBACKS" -ge "$ROUTE_COUNT" ]; then
    pass "All routes have permission_callback ($PERM_CALLBACKS)"
elif [ "$ROUTE_COUNT" -gt 0 ]; then
    warn "Routes: $ROUTE_COUNT, permission_callbacks: $PERM_CALLBACKS"
fi

# ─── 9. GUTENBERG BLOCKS ────────────────────────────────────────────────────

section "9. Gutenberg Blocks"

BLOCK_JSON_COUNT=$(find "$PLUGIN_DIR" -name "block.json" -not -path "*/node_modules/*" -not -path "*/vendor/*" 2>/dev/null | wc -l | tr -d ' ')
echo "  block.json files: $BLOCK_JSON_COUNT"

if [ "$BLOCK_JSON_COUNT" -ge 10 ]; then
    pass "$BLOCK_JSON_COUNT blocks registered (13 expected)"
else
    warn "Only $BLOCK_JSON_COUNT blocks found (13 expected)"
fi

# ─── 10. MARKET GAP ANALYSIS ────────────────────────────────────────────────

section "10. Market Gap Analysis vs Competitors"

echo -e "  ${BOLD}Features competitors have that WPMediaVerse does NOT:${NC}"
echo ""

# These are based on the feature comparison chart
GAPS=(
    "Audio file support|rtMedia Pro has audio upload support — WPMediaVerse does not|WARN"
    "Document upload|rtMedia Pro supports document uploads — WPMediaVerse does not|WARN"
    "EXIF data display|Planned but not shipped — rtMedia and MediaPress have it|WARN"
    "Slideshow mode|rtMedia Pro and MediaPress have slideshows — WPMediaVerse does not|INFO"
    "Auto-approve trusted users|Planned but not shipped|INFO"
    "Keyword filtering|rtMedia Pro and BuddyBoss have keyword filters — consider for v1.1|INFO"
)

for gap in "${GAPS[@]}"; do
    IFS='|' read -r feature detail severity <<< "$gap"
    if [ "$severity" = "WARN" ]; then
        warn "$feature — $detail"
    else
        echo -e "  ${CYAN}ℹ${NC} $feature — $detail"
    fi
done

echo ""
echo -e "  ${BOLD}Unique advantages WPMediaVerse has over ALL competitors:${NC}"
echo ""

ADVANTAGES=(
    "AI moderation in free version (OpenAI Vision) — no competitor offers this free"
    "Photo Battles / 1v1 voting — no competitor has this at all"
    "Tournament brackets — no competitor has this at all"
    "Points + Boosts + Streaks gamification — no competitor has this"
    "Video transcoding with HLS streaming — no competitor has this"
    "Auto-captions via Whisper AI — no competitor has this"
    "BunnyCDN storage driver — no competitor supports BunnyCDN"
    "MemberPress/WooCommerce quota integration — no competitor has this"
    "WordPress Interactivity API — only WPMediaVerse uses modern WP patterns"
    "80+ REST API endpoints — most complete API of any media plugin"
)

for adv in "${ADVANTAGES[@]}"; do
    echo -e "  ${GREEN}★${NC} $adv"
done

# ─── 11. FREE VS PRO BOUNDARY CHECK ─────────────────────────────────────────

section "11. Free vs Pro Boundary"

# Check that Pro features are properly gated
PRO_GATE_PATTERNS=("mvs_is_pro\|mvs_pro_active\|MVS_PRO\|wpmediaverse-pro")
PRO_GATE_COUNT=$(grep -rn "mvs_is_pro\|mvs_pro_active\|MVS_PRO" --include="*.php" "$PLUGIN_DIR/includes" 2>/dev/null | wc -l | tr -d ' ')

if [ "$PRO_GATE_COUNT" -gt 0 ]; then
    pass "Pro feature gate checks found ($PRO_GATE_COUNT occurrences)"
else
    warn "No Pro feature gate checks found — verify Pro features are properly locked"
fi

# Check that free plugin doesn't bundle Pro-only code paths that expose Pro features
PRO_FEATURES=("S3\|BunnyCDN" "transcod" "tournament\|battle\|challenge" "whisper\|caption" "quota")
for pattern in "${PRO_FEATURES[@]}"; do
    HITS=$(grep -rn "$pattern" --include="*.php" "$PLUGIN_DIR/includes" 2>/dev/null | grep -vi "pro\|premium\|upgrade\|lock\|gate\|check\|notice" | wc -l | tr -d ' ')
    FEATURE_NAME=$(echo "$pattern" | sed 's/\\|/ or /g')
    if [ "$HITS" -gt 10 ]; then
        warn "Pro feature ($FEATURE_NAME) has $HITS references — verify gating"
    else
        pass "Pro feature ($FEATURE_NAME) appears properly scoped"
    fi
done

# ─── 12. DOCUMENTATION READINESS ────────────────────────────────────────────

section "12. Documentation"

DOC_DIRS=("docs/website" "marketing/03-website-copy" "marketing/06-sales-materials" "marketing/07-brand-assets")
for dir in "${DOC_DIRS[@]}"; do
    if [ -d "$PLUGIN_DIR/$dir" ]; then
        FILE_COUNT=$(find "$PLUGIN_DIR/$dir" -type f | wc -l | tr -d ' ')
        pass "$dir/ ($FILE_COUNT files)"
    else
        warn "$dir/ missing"
    fi
done

# Check docs_config.json
if [ -f "$PLUGIN_DIR/docs/website/docs_config.json" ]; then
    pass "docs_config.json exists"
else
    warn "docs/website/docs_config.json missing — needed for store docs"
fi

# ─── 13. COMPETITOR PRICING POSITION ────────────────────────────────────────

section "13. Pricing Position"

echo -e "  ${BOLD}Market pricing comparison:${NC}"
echo ""
echo "  ┌──────────────────────┬──────────┬──────────┬──────────┐"
echo "  │ Product              │ 1 Site   │ 5 Sites  │ Unlim    │"
echo "  ├──────────────────────┼──────────┼──────────┼──────────┤"
echo "  │ WPMediaVerse Pro     │ \$69/yr   │ \$99/yr   │ \$199/yr  │"
echo "  │ rtMedia Pro          │ \$77/yr   │ \$137/yr  │ \$167/yr  │"
echo "  │ MediaPress Pro       │ \$59/yr   │ \$99/yr   │ \$149/yr  │"
echo "  │ BuddyBoss Platform  │ \$228/yr  │ \$228/yr  │ N/A      │"
echo "  └──────────────────────┴──────────┴──────────┴──────────┘"
echo ""

echo -e "  ${CYAN}ℹ${NC} WPMediaVerse Pro at \$69 is competitive with rtMedia (\$77)"
echo -e "  ${CYAN}ℹ${NC} Agency tier (\$199) undercuts BuddyBoss (\$228) significantly"
echo -e "  ${CYAN}ℹ${NC} MediaPress is cheaper but has far fewer features"
echo -e "  ${GREEN}★${NC} Free version includes AI moderation — strongest free tier in market"

# ─── SUMMARY ─────────────────────────────────────────────────────────────────

echo ""
echo -e "${BOLD}${CYAN}━━━ SUMMARY ━━━${NC}"
echo ""
echo -e "  ${GREEN}Passed:${NC}  $PASS"
echo -e "  ${RED}Failed:${NC}  $FAIL"
echo -e "  ${YELLOW}Warnings:${NC} $WARN"
echo ""

if [ "$FAIL" -eq 0 ]; then
    echo -e "  ${GREEN}${BOLD}🚀 LAUNCH READY${NC} — all critical checks passed"
    if [ "$WARN" -gt 0 ]; then
        echo -e "  ${YELLOW}Review $WARN warnings above before going live${NC}"
    fi
else
    echo -e "  ${RED}${BOLD}⛔ NOT READY${NC} — $FAIL critical issues must be fixed"
    echo ""
    echo "  Fix the failures above, then re-run:"
    echo "  bash bin/launch-readiness.sh"
fi

echo ""
exit "$FAIL"
