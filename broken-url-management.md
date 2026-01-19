# Broken URL Management Module - Implementation Plan

## Module Overview

**Module Name**: Broken Link Scanner & Fixer  
**Module Folder**: `modules/broken-url-management/`  
**Database Tables**: 
- `{prefix}seoautofix_broken_links_scan_results`
- `{prefix}seoautofix_broken_links_scans`
- `{prefix}seoautofix_broken_links_fixes_history`

**Primary Goal**: Automatically detect broken links (4xx, 5xx errors) on published WordPress pages, identify their locations (anchor text, naked links in header, footer, content, images), suggest relevant replacements, provide a review queue before applying fixes, and enable bulk operations with revert capability.

---

## Feature Specifications

### 1. Scanning Requirements

#### **What Gets Scanned**
- ✅ **Published Pages Only** - Only scan content that is publicly visible
- ✅ **All Link Types**:
  - Anchor text links (`<a href="...">text</a>`)
  - Naked URLs (plain text URLs)
  - Image sources (`<img src="...">`)
  - Background images in CSS
- ✅ **All Locations**:
  - Header
  - Footer
  - Main content area
  - Sidebar widgets
  - Custom post types

#### **What Gets Detected**
- HTTP status codes: **4xx errors** (404, 403, 410, etc.)
- HTTP status codes: **5xx errors** (500, 502, 503, etc.)
- Timeout errors
- DNS resolution failures

#### **Scan Strategy**
1. Query all published posts and pages from WordPress database
2. Extract HTML content including custom fields
3. Parse and identify all links with their context
4. Test each unique URL for status code
5. Record broken links with:
   - Page where found
   - Link location (header/footer/content/image)
   - Anchor text or context
   - Status code
   - Link type (internal/external)

---

### 2. Results Display & Organization

#### **Broken Link Information**
Each broken link entry shows:
- **Page Name**: Where the broken link was found (e.g., "Home Page", "Contact Page")
- **Link Location**: Where on the page (Header, Footer, Content, Image)
- **Anchor Text/Context**: The clickable text or image alt text
- **Broken URL**: The actual broken link
- **Status Code**: HTTP error code (404, 500, etc.)
- **Link Type**: Internal or External
- **Occurrences Count**: How many pages have this same broken link

#### **Occurrences & Bulk Actions**
- If the same broken URL appears on multiple pages (e.g., 37 pages), show:
  - **Occurrences**: "Found on 37 pages"
  - **Bulk Action**: Fix all occurrences at once
  - **Expandable List**: Click to see all pages where this link appears

**Example Display**:
```
Broken URL: example.com/old-page (404 Error)
Occurrences: 37 pages
[View All Pages ▼] [Fix All]

When expanded:
├─ Home Page - Anchor Text: "Click here" - Location: Footer
├─ About Page - Anchor Text: "Learn more" - Location: Content
├─ Blog Post #1 - Anchor Text: "Read this" - Location: Content
... (34 more)
```

---

### 3. Export Options (Before Fixing)

#### **Export Report Without Solving**
Users can export the broken links report **before** making any fixes:
- **CSV Export**: Download spreadsheet with all broken links
- **PDF Export**: Formatted report for sharing
- **Email Report**: Send report to specified email address

**Report Contents**:
- Total broken links found
- Breakdown by status code (4xx vs 5xx)
- Breakdown by type (internal vs external)
- List of all broken links with page locations
- Timestamp of scan

---

### 4. Auto-Fix Workflow with Relevance Scanning

#### **Step 1: Initiate Auto-Fix**
When user clicks "Start Auto Fix":
1. System analyzes each broken link
2. For **internal links**: Scans site to find most relevant replacement
3. For **external links**: Offers removal option only

#### **Step 2: Relevance Scanning Algorithm**

##### **For Internal Broken Links**
The system finds the best replacement using:

1. **URL Path Similarity** (Highest Priority)
   - Compare URL structure and segments
   - Match keywords in URL path
   - Score: 0-100

2. **Content Similarity**
   - Extract slug from broken URL
   - Search for pages with similar titles/slugs
   - Score: 0-100

3. **Category/Tag Matching** (For Posts)
   - If broken URL was a post, find posts in same category
   - Score: 0-100

4. **Fallback Options**:
   - **First Fallback**: Next most relevant page (if primary match score < 70)
   - **Second Fallback**: Homepage (if no relevant pages found)
   - **User Override**: User can manually enter custom URL

**Suggested Redirect Display**:
```
Broken Link: /old-product-page (404 Error)
Suggested Redirect: /new-product-page
Confidence: 95% - High match (URL similarity + content match)

Options:
○ Use Suggested URL
○ Enter Custom URL: [____________]
○ Redirect to Home Page
```

##### **For External Broken Links**
- **No automatic replacement** (we don't have access to external domains)
- **Options Provided**:
  - Remove the link (convert to plain text)
  - User manually enters new URL
  - Delete the entire element (if it's just a link with no valuable text)

---

### 5. Fix Plan Review Queue (CRITICAL FEATURE)

> [!IMPORTANT]
> Users MUST review all proposed fixes before they are applied to prevent incorrect replacements.

#### **Workflow: Generate Fix Plan → Review → Apply**

##### **Step 1: Generate Fix Plan**
After scanning for relevant replacements, system creates a **Fix Plan** showing:

| Found On Page | Location | Anchor Text/Alt | Old URL | New URL | Fix Type | Action |
|---------------|----------|-----------------|---------|---------|----------|--------|
| Home Page | Footer | "Click here" | abc.com/404 | abc.com/new | Replace | ✓ |
| About Page | Content | "Learn more" | xyz.com/old | xyz.com/current | Replace | ✓ |
| Blog Post | Image | "Product photo" | img.com/missing.jpg | *Remove* | Remove | ✓ |
| Contact | Header | External link | external.com/404 | *Remove* | Remove | ✓ |

##### **Step 2: Review Interface**
Users can:
- ✅ **Review each fix** side-by-side (old vs new)
- ✅ **Edit suggested URL** inline
- ✅ **Uncheck fixes** they don't want to apply
- ✅ **Change fix type** (Replace → Remove, or vice versa)
- ✅ **See preview** of how the page will look after fix

##### **Step 3: Apply Changes**
- User clicks **"Apply Selected Fixes"**
- System applies only checked fixes
- Creates backup/history entry for reverting

---

### 6. Batch Processing for Large Sites

> [!WARNING]
> Large websites can cause timeout errors. Batch processing is essential.

#### **Implementation Strategy**
1. **Chunk Scanning**: Process pages in batches of 10-20
2. **Progress Tracking**: Real-time progress bar showing:
   - Pages scanned: 45/200
   - Links tested: 1,234/5,678
   - Broken links found: 23
3. **AJAX-Based Processing**: Prevent PHP timeouts
4. **Resume Capability**: If scan is interrupted, resume from last checkpoint
5. **Background Processing**: Option to run scan in background using WP Cron

**Batch Configuration** (Admin Settings):
- Batch size: 10-50 pages per batch (default: 20)
- Delay between batches: 1-5 seconds (default: 2s)
- Timeout per request: 10-30 seconds (default: 15s)

---

### 7. Revert Functionality

> [!CAUTION]
> Reverting changes is critical in case fixes cause issues.

#### **How Revert Works**
1. **Before Applying Fixes**: System creates snapshot of original content
2. **History Tracking**: Store in `{prefix}seoautofix_broken_links_fixes_history`
3. **Revert Options**:
   - Revert all changes from last fix session
   - Revert specific pages only
   - Revert individual links

**History Entry Structure**:
```
Fix Session ID: #12345
Date: 2026-01-16 10:30 AM
Total Fixes Applied: 45
Status: Active (can be reverted)

[Revert All] [View Details]
```

#### **Revert Process**
1. User clicks **"Undo Changes"**
2. System shows what will be reverted
3. User confirms
4. Original content is restored
5. Broken links are marked as "unfixed" again

---

### 8. Post-Fix Verification & Reporting

#### **Auto Re-Scan After Fixing**
After applying fixes:
1. **Automatic re-scan** of all fixed pages
2. **Verification** that broken links are now resolved
3. **Report Generation**:
   - ✅ Successfully fixed: 42 links
   - ⚠️ Still broken: 3 links (need manual review)
   - ❌ New errors: 0 links

#### **Fixed Report Export Options**
- **Download Fixed Report** (CSV/PDF)
- **Email Fixed Report** to specified address
- **Report Contents**:
  - Summary of fixes applied
  - Before/after comparison
  - Verification results
  - Any remaining issues

---

## User Interface Design

### Main Admin Page Layout

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Broken Link Scanner & Fixer                                            │
│                                                                          │
│  Broken Links: 8    4xx Errors: 5    5xx Errors: 3                     │
├─────────────────────────────────────────────────────────────────────────┤
│  [Export Report]  [Start Auto Fix]                                      │
├─────────────────────────────────────────────────────────────────────────┤
│  Filters:                                                                │
│  [Showing Published Pages Only ▼] [All Errors ▼] [All Locations ▼]     │
│  [Search URL...] [Filter]                                               │
│                                                                          │
│  Page 1 of 2                          Show [10 ▼] entries              │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│ Page          │ Broken Link              │ Status      │ Action         │
├───────────────┼──────────────────────────┼─────────────┼────────────────┤
│ Home Page     │ Anchor Text: "XYZ Link"  │ 404 Error   │ [Fix ▼]       │
│               │ abc.com                   │ [4xx]       │                │
├───────────────┼──────────────────────────┼─────────────┼────────────────┤
│ Contact Page  │ Anchor Text: "XYZ Link"  │ 404 Error   │ [Fix ▼]       │
│               │ arom                      │ [4xx]       │                │
├───────────────┼──────────────────────────┼─────────────┼────────────────┤
│ Blog Post     │ Naked Link: example.com  │ 500 Error   │ [Fix ▼]       │
│               │                           │ [5xx]       │                │
└─────────────────────────────────────────────────────────────────────────┘
```

### Auto Fix Interface

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Auto Fix Broken Links                                                  │
├─────────────────────────────────────────────────────────────────────────┤
│  Home Page                                                              │
│  Broken Link: abc.com (404 Error)                                       │
│                                                                          │
│  Suggested Redirect: www.example.com/highly-relevant-page               │
│                                                                          │
│  ○ Use Suggested URL                                                    │
│  ○ + Enter Custom URL                                                   │
│  ○ Or Redirect to Home Page                                             │
│                                                                          │
│  [Apply Fix]  [Skip]                                                    │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│  [Remove Broken Links]  [Replace Broken Links]  [Fix All Issues ▼]     │
├─────────────────────────────────────────────────────────────────────────┤
│  [↺ Undo Changes]                                                       │
│  [📥 Download Fixed Report]  [📧 Email Fixed Report]                    │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│  ✓ All Issues Fixed! No Broken Links Found.                            │
│                                                                          │
│  [📥 Download Fixed Report .csv]  [📧 Email Fixed Report ↗]            │
└─────────────────────────────────────────────────────────────────────────┘
```

### Fix Plan Review Queue Interface

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Review Fix Plan Before Applying                                        │
│                                                                          │
│  Total Fixes Proposed: 45                                               │
│  ✓ Select All  |  Unselect All                                          │
├─────────────────────────────────────────────────────────────────────────┤
│ ☑ │ Page      │ Location │ Anchor/Alt │ Old URL → New URL │ Fix Type   │
├───┼───────────┼──────────┼────────────┼───────────────────┼────────────┤
│ ☑ │ Home      │ Footer   │ "Click"    │ old.com → new.com │ Replace    │
│ ☑ │ About     │ Content  │ "Learn"    │ abc.com → xyz.com │ Replace    │
│ ☐ │ Blog #1   │ Image    │ "Photo"    │ img.com → [Remove]│ Remove     │
│ ☑ │ Contact   │ Header   │ "External" │ ext.com → [Remove]│ Remove     │
└─────────────────────────────────────────────────────────────────────────┘
│  [Apply Selected Fixes (43)]  [Cancel]                                  │
└─────────────────────────────────────────────────────────────────────────┘
```

### Occurrences View (Bulk Actions)

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Broken URL: example.com/old-page                                       │
│  Status: 404 Error                                                      │
│  Occurrences: Found on 37 pages                                         │
│                                                                          │
│  [Fix All 37 Occurrences] [View Details ▼]                              │
├─────────────────────────────────────────────────────────────────────────┤
│  When expanded:                                                         │
│  ├─ Home Page - "Click here" - Footer                                   │
│  ├─ About Page - "Learn more" - Content                                 │
│  ├─ Blog Post #1 - "Read this" - Content                                │
│  ├─ Blog Post #2 - Naked Link - Content                                 │
│  ... (33 more pages)                                                    │
│                                                                          │
│  Suggested Replacement: example.com/new-page                            │
│  [Apply to All] [Customize Per Page]                                    │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Database Schema

### Table 1: `{prefix}seoautofix_broken_links_scan_results`

```sql
CREATE TABLE {prefix}seoautofix_broken_links_scan_results (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scan_id VARCHAR(50) NOT NULL,
    found_on_page_id BIGINT(20) NOT NULL,              -- WordPress post/page ID
    found_on_page_title VARCHAR(255) NOT NULL,         -- Page title for display
    found_on_url TEXT NOT NULL,                        -- Full URL of page
    broken_url TEXT NOT NULL,                          -- The broken link
    link_location ENUM('header', 'footer', 'content', 'sidebar', 'image') NOT NULL,
    anchor_text TEXT NULL,                             -- Anchor text or image alt
    link_context TEXT NULL,                            -- Surrounding text for context
    link_type ENUM('internal', 'external') NOT NULL,
    status_code INT NOT NULL,                          -- HTTP status code
    error_type ENUM('4xx', '5xx', 'timeout', 'dns') NOT NULL,
    suggested_url TEXT NULL,                           -- Algorithm suggestion
    suggestion_confidence INT DEFAULT 0,               -- 0-100 confidence score
    user_modified_url TEXT NULL,                       -- User's manual edit
    fix_type ENUM('replace', 'remove', 'redirect') NULL,
    is_fixed TINYINT(1) DEFAULT 0,
    is_deleted TINYINT(1) DEFAULT 0,
    occurrences_count INT DEFAULT 1,                   -- How many pages have this URL
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_scan_id (scan_id),
    INDEX idx_broken_url (broken_url(255)),
    INDEX idx_link_type (link_type),
    INDEX idx_status_code (status_code),
    INDEX idx_is_fixed (is_fixed),
    INDEX idx_found_on_page_id (found_on_page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table 2: `{prefix}seoautofix_broken_links_scans`

```sql
CREATE TABLE {prefix}seoautofix_broken_links_scans (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scan_id VARCHAR(50) UNIQUE NOT NULL,
    total_pages_found INT DEFAULT 0,
    total_pages_scanned INT DEFAULT 0,
    total_links_tested INT DEFAULT 0,
    total_broken_links INT DEFAULT 0,
    total_4xx_errors INT DEFAULT 0,
    total_5xx_errors INT DEFAULT 0,
    status ENUM('in_progress', 'completed', 'failed', 'paused') DEFAULT 'in_progress',
    current_batch INT DEFAULT 0,
    total_batches INT DEFAULT 0,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_scan_id (scan_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table 3: `{prefix}seoautofix_broken_links_fixes_history`

```sql
CREATE TABLE {prefix}seoautofix_broken_links_fixes_history (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fix_session_id VARCHAR(50) NOT NULL,
    scan_id VARCHAR(50) NOT NULL,
    page_id BIGINT(20) NOT NULL,
    original_content LONGTEXT NOT NULL,                -- Backup of original content
    modified_content LONGTEXT NOT NULL,                -- Content after fixes
    fixes_applied JSON NOT NULL,                       -- Array of fixes applied
    total_fixes INT DEFAULT 0,
    is_reverted TINYINT(1) DEFAULT 0,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reverted_at DATETIME NULL,
    INDEX idx_fix_session_id (fix_session_id),
    INDEX idx_scan_id (scan_id),
    INDEX idx_page_id (page_id),
    INDEX idx_is_reverted (is_reverted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Module File Structure

```
modules/broken-url-management/
├── class-broken-url-management.php       # Main module class
├── class-link-scanner.php                # Scans published pages for links
├── class-link-tester.php                 # Tests links for status codes
├── class-link-analyzer.php               # Relevance matching algorithm
├── class-fix-plan-manager.php            # Manages fix plan review queue
├── class-batch-processor.php             # Handles batch processing
├── class-fix-applier.php                 # Applies fixes to content
├── class-history-manager.php             # Manages fix history & revert
├── class-database-manager.php            # Database operations
├── class-export-manager.php              # Export reports (CSV, PDF, Email)
├── assets/
│   ├── css/
│   │   └── broken-url-management.css
│   └── js/
│       ├── broken-url-management.js      # Main UI logic
│       ├── fix-plan-review.js            # Fix plan review interface
│       └── batch-processor.js            # AJAX batch processing
└── views/
    ├── admin-page.php                    # Main admin interface
    ├── fix-plan-review.php               # Fix plan review UI
    ├── occurrences-view.php              # Bulk occurrences view
    └── export-options.php                # Export dialog
```

---

## Implementation Workflow

### Phase 1: Scanning Published Pages
1. Query WordPress database for all published posts/pages
2. Extract content including custom fields, headers, footers
3. Parse HTML to identify all links with context
4. Categorize links by location (header/footer/content/image)
5. Extract anchor text or image alt text

### Phase 2: Batch Processing & Link Testing
1. Divide links into batches (default: 20 per batch)
2. Test each unique URL for HTTP status code
3. Identify 4xx and 5xx errors
4. Update progress bar in real-time via AJAX
5. Store results with full context

### Phase 3: Occurrences Detection
1. Group broken links by URL
2. Count how many pages have the same broken link
3. Create expandable view for bulk actions

### Phase 4: Relevance Scanning (Auto-Fix)
1. For internal broken links:
   - Run URL similarity algorithm
   - Search for content matches
   - Calculate confidence score
   - Suggest best replacement or fallback
2. For external broken links:
   - Offer removal option only

### Phase 5: Fix Plan Generation
1. Create comprehensive fix plan with all proposed changes
2. Show side-by-side comparison (old URL → new URL)
3. Allow inline editing of suggestions
4. Enable checkbox selection for applying fixes

### Phase 6: Review Queue
1. Display fix plan in table format
2. Allow users to:
   - Check/uncheck individual fixes
   - Edit suggested URLs
   - Change fix type (replace/remove)
3. Show preview of changes

### Phase 7: Apply Fixes
1. Create backup of original content
2. Apply only selected fixes
3. Store in history table for revert capability
4. Update database to mark as fixed

### Phase 8: Post-Fix Verification
1. Re-scan all fixed pages
2. Verify broken links are resolved
3. Generate fixed report
4. Offer export/email options

### Phase 9: Revert Capability
1. Allow users to undo changes
2. Restore original content from history
3. Mark fixes as reverted

---

## AJAX Endpoints

### 1. Start Scan
- **Action**: `wp_ajax_seoautofix_broken_links_start_scan`
- **Method**: POST
- **Returns**: `{scan_id, status, total_pages, message}`

### 2. Get Scan Progress
- **Action**: `wp_ajax_seoautofix_broken_links_get_progress`
- **Method**: GET
- **Params**: `scan_id`
- **Returns**: `{progress, pages_scanned, links_tested, broken_count, current_batch, total_batches}`

### 3. Get Results
- **Action**: `wp_ajax_seoautofix_broken_links_get_results`
- **Method**: GET
- **Params**: `scan_id, filter, search, page, per_page`
- **Returns**: `{results: [], total, pages, occurrences_grouped: []}`

### 4. Generate Fix Plan
- **Action**: `wp_ajax_seoautofix_broken_links_generate_fix_plan`
- **Method**: POST
- **Params**: `scan_id, selected_ids[]`
- **Returns**: `{fix_plan: [], total_fixes, confidence_scores: {}}`

### 5. Update Fix Plan Entry
- **Action**: `wp_ajax_seoautofix_broken_links_update_fix_plan`
- **Method**: POST
- **Params**: `id, new_url, fix_type`
- **Returns**: `{success, message}`

### 6. Apply Fixes
- **Action**: `wp_ajax_seoautofix_broken_links_apply_fixes`
- **Method**: POST
- **Params**: `fix_session_id, selected_fixes[]`
- **Returns**: `{success, fixes_applied, failed_count, fix_session_id, messages}`

### 7. Revert Fixes
- **Action**: `wp_ajax_seoautofix_broken_links_revert_fixes`
- **Method**: POST
- **Params**: `fix_session_id`
- **Returns**: `{success, reverted_count, message}`

### 8. Export Report
- **Action**: `wp_ajax_seoautofix_broken_links_export_report`
- **Method**: POST
- **Params**: `scan_id, format (csv|pdf), email (optional)`
- **Returns**: `{success, download_url, message}` or sends email

### 9. Get Occurrences
- **Action**: `wp_ajax_seoautofix_broken_links_get_occurrences`
- **Method**: GET
- **Params**: `broken_url, scan_id`
- **Returns**: `{occurrences: [], total_count, suggested_replacement}`

### 10. Bulk Fix Occurrences
- **Action**: `wp_ajax_seoautofix_broken_links_bulk_fix`
- **Method**: POST
- **Params**: `broken_url, replacement_url, occurrence_ids[]`
- **Returns**: `{success, fixed_count, message}`

---

## Relevance Scanning Algorithm (Detailed)

### Algorithm Flow

```javascript
function findRelevantReplacement(brokenUrl, siteContent) {
    let suggestions = [];
    
    // Step 1: URL Path Similarity (50% weight)
    const pathMatches = findPathSimilarURLs(brokenUrl, siteContent);
    suggestions.push(...pathMatches.map(m => ({
        url: m.url,
        score: m.score * 0.5,
        reason: 'URL path similarity'
    })));
    
    // Step 2: Content/Slug Similarity (30% weight)
    const contentMatches = findContentSimilarPages(brokenUrl, siteContent);
    suggestions.push(...contentMatches.map(m => ({
        url: m.url,
        score: m.score * 0.3,
        reason: 'Content similarity'
    })));
    
    // Step 3: Category/Tag Matching (20% weight)
    const categoryMatches = findCategorySimilarPosts(brokenUrl, siteContent);
    suggestions.push(...categoryMatches.map(m => ({
        url: m.url,
        score: m.score * 0.2,
        reason: 'Category match'
    })));
    
    // Aggregate scores by URL
    const aggregated = aggregateScores(suggestions);
    
    // Sort by score
    aggregated.sort((a, b) => b.totalScore - a.totalScore);
    
    // Decision logic
    if (aggregated.length === 0 || aggregated[0].totalScore < 30) {
        return {
            url: getHomeUrl(),
            confidence: 0,
            reason: 'No relevant pages found, redirecting to homepage'
        };
    }
    
    if (aggregated[0].totalScore >= 70) {
        return {
            url: aggregated[0].url,
            confidence: aggregated[0].totalScore,
            reason: 'High confidence match - ' + aggregated[0].reasons.join(', ')
        };
    }
    
    // Medium confidence - offer primary + fallback
    return {
        url: aggregated[0].url,
        fallback: aggregated[1]?.url || getHomeUrl(),
        confidence: aggregated[0].totalScore,
        reason: 'Medium confidence match - ' + aggregated[0].reasons.join(', ')
    };
}
```

### Confidence Levels

| Score Range | Confidence | Action |
|-------------|------------|--------|
| 90-100 | Very High | Auto-suggest with high confidence badge |
| 70-89 | High | Suggest with confidence indicator |
| 50-69 | Medium | Suggest with warning, offer fallback |
| 30-49 | Low | Suggest with caution, show homepage fallback |
| 0-29 | Very Low | Redirect to homepage |

---

## Security Considerations

- ✅ **Nonce verification** for all AJAX requests
- ✅ **Capability check**: `manage_options` or custom capability
- ✅ **Sanitize all user inputs** (especially edited URLs)
- ✅ **Validate URLs** before applying fixes (prevent XSS)
- ✅ **Escape output** in admin views
- ✅ **Rate limiting** on scanning to prevent abuse
- ✅ **Database prepared statements** to prevent SQL injection
- ✅ **Content backup** before applying fixes (stored in history table)

---

## Performance Optimizations

1. **Batch Processing**: Process 10-20 pages per batch to avoid timeouts
2. **AJAX Chunking**: Use AJAX to process batches asynchronously
3. **Database Indexing**: Index frequently queried columns
4. **Caching**: Cache link test results for 24 hours
5. **Lazy Loading**: Load occurrences on demand (don't load all at once)
6. **Pagination**: Show 10-50 results per page
7. **Background Processing**: Option to use WP Cron for large scans
8. **Transients**: Use WordPress transients for temporary data

---

## Testing Scenarios

### Test Case 1: Published Pages Only
- **Setup**: Create 5 published pages, 3 draft pages
- **Expected**: Only 5 published pages are scanned
- **Verify**: Draft pages are not included in scan

### Test Case 2: Anchor Text Detection
- **Setup**: Page with anchor text link `<a href="broken.com">Click Here</a>`
- **Expected**: Broken link detected with anchor text "Click Here"
- **Verify**: Anchor text is displayed in results

### Test Case 3: Naked Link Detection
- **Setup**: Page with plain text URL `https://broken-site.com/404`
- **Expected**: Naked link detected and marked as such
- **Verify**: Link type shows "Naked Link"

### Test Case 4: Image Source Detection
- **Setup**: Page with `<img src="missing-image.jpg" alt="Photo">`
- **Expected**: Broken image detected with alt text
- **Verify**: Location shows "Image", alt text displayed

### Test Case 5: Occurrences Count
- **Setup**: Same broken link on 37 different pages
- **Expected**: Grouped as 1 entry with "37 occurrences"
- **Verify**: Bulk action available, expandable list shows all pages

### Test Case 6: Fix Plan Review
- **Setup**: Generate fix plan with 10 broken links
- **Expected**: Review queue shows all 10 with checkboxes
- **Verify**: Can uncheck items, edit URLs, change fix types

### Test Case 7: Batch Processing
- **Setup**: Site with 200 pages
- **Expected**: Scan completes without timeout
- **Verify**: Progress bar updates, batches processed sequentially

### Test Case 8: Revert Functionality
- **Setup**: Apply 20 fixes, then revert
- **Expected**: Original content restored
- **Verify**: Broken links marked as unfixed again

### Test Case 9: Export Before Fixing
- **Setup**: Scan finds 15 broken links
- **Expected**: Can export CSV/PDF without fixing
- **Verify**: Report contains all broken links with details

### Test Case 10: Post-Fix Verification
- **Setup**: Fix 10 broken links
- **Expected**: Auto re-scan verifies fixes
- **Verify**: Fixed report shows success count

---

## Success Criteria

- ✅ Scans **only published pages** (no drafts, private, or pending)
- ✅ Detects broken links in **all locations** (header, footer, content, images)
- ✅ Identifies **anchor text and naked links** correctly
- ✅ Groups **same broken URL occurrences** for bulk actions
- ✅ Provides **fix plan review queue** before applying changes
- ✅ Handles **large sites** (500+ pages) without timeout
- ✅ Offers **export options** before and after fixing
- ✅ Implements **revert functionality** for all fixes
- ✅ **Auto re-scans** after fixes to verify success
- ✅ Provides **email/download** options for reports
- ✅ UI matches provided design layout
- ✅ Module is self-contained and follows WordPress best practices

---

## Future Enhancements

1. **Scheduled Scans**: Weekly/monthly automatic scans with email notifications
2. **Link Monitoring**: Track external links over time, alert when they break
3. **Broken Image Replacement**: Suggest similar images from media library
4. **301 Redirect Creation**: Automatically create redirects for broken internal links
5. **Link Health Score**: Overall site health score based on broken link percentage
6. **Integration with Google Search Console**: Import broken links from GSC
7. **Multi-site Support**: Scan multiple sites in a network
8. **API Integration**: Allow third-party tools to trigger scans

---

**Document Version**: 2.0  
**Last Updated**: 2026-01-16  
**Status**: Planning Phase - Comprehensive Requirements Defined
