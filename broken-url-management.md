# Broken URL Management Module - Implementation Plan

## Module Overview

**Module Name**: 404 & Broken URL Management  
**Module Folder**: `modules/broken-url-management/`  
**Database Table**: `{prefix}seoautofix_broken_links_scan_results`  
**Primary Goal**: Automatically detect broken links (404, 4xx, 5xx errors) on the WordPress site, suggest closest relevant URLs for internal links, and provide management interface for fixing broken links.

---

## Feature Specifications

### 1. Web Crawler Functionality

#### **What It Does**
- Crawls the entire WordPress website to identify all links (internal and external)
- Tests each link to check HTTP status codes
- Identifies problematic links: 404, 4xx, 5xx status codes
- Categorizes links as "Internal" or "External"

#### **Crawling Strategy**
1. Start with the homepage
2. Extract all links from page content
3. Recursively crawl internal pages
4. Test all links (both internal and external)
5. Store results in database

#### **Technical Approach**
- Use WordPress HTTP API (`wp_remote_get()`) for testing links
- Implement batch processing to avoid timeouts
- Add progress tracking for user feedback
- Implement rate limiting to avoid overwhelming servers
- Cache results to avoid re-testing same URLs

### 2. Link Analysis & Suggestion Algorithm

#### **For Internal Broken Links**

##### **Step 1: Find Closest Relevant Link**
Algorithm to find the most relevant replacement:

1. **URL Path Similarity Matching**
   - Extract URL path segments from broken URL
   - Compare with all valid internal URLs
   - Calculate similarity score based on:
     * Path segment overlap
     * Keyword matching
     * URL structure similarity

2. **Content-Based Matching** (if path matching fails)
   - Extract post/page slug from broken URL
   - Search for posts/pages with similar slugs or titles
   - Use WordPress search functionality
   - Score based on title/slug similarity

3. **Category/Tag Matching** (for post URLs)
   - If broken URL was a post, identify its category/tags (if available in URL)
   - Find similar posts in same category
   - Rank by relevance

4. **Fallback to Homepage**
   - If no relevant match found, suggest homepage
   - Provide reason: "Nothing relevant was found, redirected to the home URL"

##### **Step 2: Reason Generation**
Based on matching results, generate one of these reasons:
- "This is the closest relevant link we found" (if match found)
- "Nothing relevant was found, redirected to the home URL" (if no match)

#### **For External Broken Links**
- No automatic suggestion algorithm
- Display message: "This link is not working, either delete it or provide a new link"
- User must manually intervene (edit or delete)

### 3. User Interface Design

#### **Main Admin Page Layout**

```
┌─────────────────────────────────────────────────────────────────┐
│  404 & Broken URL Management                                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  [Start New Scan]  [View Last Scan Results]                     │
│                                                                  │
│  Scan Progress: ████████████░░░░░░░░ 60% (120/200 URLs tested) │
│                                                                  │
├─────────────────────────────────────────────────────────────────┤
│  Broken Links Found: 15                                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  [Filter: All | Internal | External]  [Search...]              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────────────┐
│ #  │ Type     │ Current URL              │ Suggested URL      │ Reason      │ Action │
├────┼──────────┼──────────────────────────┼────────────────────┼─────────────┼────────┤
│ 1  │ Internal │ /old-product-page        │ /new-product       │ Closest ... │ [Del]  │
│ 2  │ Internal │ /blog/deleted-post       │ /                  │ Nothing ... │ [Del]  │
│ 3  │ External │ https://example.com/404  │ [Edit inline...]   │ Manual edit │ [Del]  │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

#### **Table Columns (In Order)**

| # | Column Name | Description | Editable |
|---|-------------|-------------|----------|
| 1 | **Serial Number** | Auto-incrementing row number | No |
| 2 | **Type** | Badge showing "Internal" or "External" | No |
| 3 | **Current URL** | The broken link that was found | No |
| 4 | **Suggested URL** | Algorithm's suggestion (editable inline) | **Yes** |
| 5 | **Reason** | Explanation for the suggestion | No |
| 6 | **Delete** | Button to remove the entry | Yes (button) |

#### **UI Features**
- **Filters**: All, Internal Only, External Only
- **Search**: Filter by URL keywords
- **Inline Editing**: Click suggested URL to edit
- **Bulk Actions**: Select multiple rows for batch operations
- **Pagination**: Show 25/50/100 results per page
- **Export**: Export results to CSV
- **Apply Changes**: Batch apply all suggested fixes

---

## Database Schema

### Table: `{prefix}seoautofix_broken_links_scan_results`

```sql
CREATE TABLE {prefix}seoautofix_broken_links_scan_results (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scan_id VARCHAR(50) NOT NULL,                    -- Unique scan identifier
    found_on_url TEXT NOT NULL,                      -- Page where broken link was found
    broken_url TEXT NOT NULL,                        -- The broken link
    link_type ENUM('internal', 'external') NOT NULL, -- Link type
    status_code INT NOT NULL,                        -- HTTP status code (404, 500, etc.)
    suggested_url TEXT NULL,                         -- Algorithm suggestion
    user_modified_url TEXT NULL,                     -- User's manual edit
    reason TEXT NOT NULL,                            -- Reason for suggestion
    is_fixed TINYINT(1) DEFAULT 0,                   -- Whether fix has been applied
    is_deleted TINYINT(1) DEFAULT 0,                 -- Whether entry was deleted
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_scan_id (scan_id),
    INDEX idx_link_type (link_type),
    INDEX idx_status_code (status_code),
    INDEX idx_is_fixed (is_fixed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `{prefix}seoautofix_broken_links_scans`

```sql
CREATE TABLE {prefix}seoautofix_broken_links_scans (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scan_id VARCHAR(50) UNIQUE NOT NULL,
    total_urls_found INT DEFAULT 0,
    total_urls_tested INT DEFAULT 0,
    total_broken_links INT DEFAULT 0,
    status ENUM('in_progress', 'completed', 'failed') DEFAULT 'in_progress',
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_scan_id (scan_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Module File Structure

```
modules/broken-url-management/
├── class-broken-url-management.php       # Main module class
├── class-link-crawler.php                # Web crawler implementation
├── class-link-analyzer.php               # URL similarity & matching algorithm
├── class-link-tester.php                 # HTTP status code testing
├── class-database-manager.php            # Database operations
├── class-url-similarity.php              # URL matching algorithm
├── assets/
│   ├── css/
│   │   └── broken-url-management.css     # Module-specific styles
│   └── js/
│       └── broken-url-management.js      # Frontend JavaScript
└── views/
    └── admin-page.php                    # Main admin interface
```

---

## Implementation Approach

### Phase 1: Core Infrastructure
1. ✅ Create module folder structure
2. ✅ Create main class file with WordPress hooks
3. ✅ Set up database schema
4. ✅ Create admin menu page
5. ✅ Implement basic UI scaffold

### Phase 2: Link Crawler
1. ✅ Implement crawler to extract all links from site
2. ✅ Build queue system for processing
3. ✅ Add progress tracking
4. ✅ Implement batch processing to avoid timeouts
5. ✅ Store discovered links in database

### Phase 3: Link Testing
1. ✅ Test each link for HTTP status code
2. ✅ Handle timeouts and errors gracefully
3. ✅ Implement rate limiting
4. ✅ Store results (broken vs. working links)

### Phase 4: Similarity Algorithm (Internal Links)
1. ✅ Implement URL path comparison
2. ✅ Build slug/title matching logic
3. ✅ Add category/tag matching
4. ✅ Calculate relevance scores
5. ✅ Determine best suggestion or fallback to homepage

### Phase 5: Frontend UI
1. ✅ Display scan results in table
2. ✅ Implement filters (All/Internal/External)
3. ✅ Add inline editing for suggestions
4. ✅ Build search functionality
5. ✅ Add pagination
6. ✅ Implement delete functionality

### Phase 6: Fixing & Application
1. ✅ Batch apply suggested fixes
2. ✅ Update actual content (replace broken links)
3. ✅ Track what's been fixed
4. ✅ Provide undo functionality (if needed)

### Phase 7: Polish & Testing
1. ✅ Add export to CSV
2. ✅ Improve UI/UX
3. ✅ Add loading states and animations
4. ✅ Test with various website sizes
5. ✅ Performance optimization

---

## URL Similarity Algorithm Details

### Algorithm Pseudocode

```javascript
function findClosestMatch(brokenUrl, allValidUrls) {
    let bestMatch = null;
    let bestScore = 0;
    
    // Step 1: Extract path segments from broken URL
    const brokenSegments = extractPathSegments(brokenUrl);
    
    for (const validUrl of allValidUrls) {
        let score = 0;
        const validSegments = extractPathSegments(validUrl);
        
        // Score based on path segment overlap
        score += calculateSegmentOverlap(brokenSegments, validSegments) * 50;
        
        // Score based on slug/keyword similarity
        score += calculateSlugSimilarity(brokenUrl, validUrl) * 30;
        
        // Score based on URL structure
        score += calculateStructureSimilarity(brokenUrl, validUrl) * 20;
        
        if (score > bestScore) {
            bestScore = score;
            bestMatch = validUrl;
        }
    }
    
    // If best match score is too low (< 30), return homepage
    if (bestScore < 30) {
        return {
            url: getHomeUrl(),
            reason: "Nothing relevant was found, redirected to the home URL"
        };
    }
    
    return {
        url: bestMatch,
        reason: "This is the closest relevant link we found"
    };
}
```

### Similarity Scoring Components

#### 1. **Path Segment Overlap** (50% weight)
- Count matching segments between broken and valid URLs
- Example:
  * Broken: `/blog/2023/old-post`
  * Valid: `/blog/2024/new-post`
  * Matching segments: `blog` (1/3 = 33% overlap)

#### 2. **Slug/Keyword Similarity** (30% weight)
- Use Levenshtein distance or similar string comparison
- Compare URL slugs after removing common words (the, a, an, etc.)
- Example:
  * Broken slug: `old-product-review`
  * Valid slug: `new-product-review`
  * Similarity: 66% (2 out of 3 words match)

#### 3. **URL Structure Similarity** (20% weight)
- Compare URL patterns (number of segments, segment types)
- Example:
  * Broken: `/category/sub/post-name/`
  * Valid: `/category/sub/other-post/`
  * Structure match: 100% (same depth and pattern)

---

## AJAX Endpoints

### 1. Start Scan
- **Action**: `wp_ajax_seoautofix_broken_links_start_scan`
- **Method**: POST
- **Returns**: `{scan_id, status, message}`

### 2. Get Scan Progress
- **Action**: `wp_ajax_seoautofix_broken_links_get_progress`
- **Method**: GET
- **Params**: `scan_id`
- **Returns**: `{progress, total_urls, tested_urls, broken_count}`

### 3. Get Results
- **Action**: `wp_ajax_seoautofix_broken_links_get_results`
- **Method**: GET
- **Params**: `scan_id, filter, search, page, per_page`
- **Returns**: `{results: [], total, pages}`

### 4. Update Suggestion
- **Action**: `wp_ajax_seoautofix_broken_links_update_suggestion`
- **Method**: POST
- **Params**: `id, new_url`
- **Returns**: `{success, message}`

### 5. Delete Entry
- **Action**: `wp_ajax_seoautofix_broken_links_delete_entry`
- **Method**: POST
- **Params**: `id`
- **Returns**: `{success, message}`

### 6. Apply Fixes
- **Action**: `wp_ajax_seoautofix_broken_links_apply_fixes`
- **Method**: POST
- **Params**: `ids[]` (array of entry IDs to fix)
- **Returns**: `{success, fixed_count, failed_count, messages}`

---

## Questions & Decisions to Make

### Algorithm Questions

1. **URL Matching Threshold**
   - What minimum similarity score should trigger a suggestion vs. homepage redirect?
   - **Proposed**: 30% threshold (adjustable in settings)

2. **Crawler Depth Limit**
   - Should we limit how deep the crawler goes?
   - **Proposed**: No limit, but add max pages setting (default: 1000 pages)

3. **External Link Testing**
   - Should we test external links on every scan or cache results?
   - **Proposed**: Cache for 7 days, allow manual re-test

4. **Performance Considerations**
   - How many URLs to test per batch?
   - **Proposed**: 10 URLs per batch, 2-second delay between batches

### UI/UX Questions

1. **Auto-Apply Suggestions**
   - Should there be an option to auto-apply fixes for high-confidence matches?
   - **Proposed**: Add checkbox option, disabled by default

2. **Notification System**
   - Should we notify users when scan completes?
   - **Proposed**: Yes, via admin notice + optional email

3. **History Tracking**
   - Should we keep history of previous scans?
   - **Proposed**: Yes, show last 10 scans in dropdown

---

## Testing Scenarios

### Test Case 1: Internal Link - Close Match
- **Broken URL**: `/products/old-smartphone-review`
- **Expected**: Find `/products/new-smartphone-review`
- **Reason**: "This is the closest relevant link we found"

### Test Case 2: Internal Link - No Match
- **Broken URL**: `/random-deleted-page-xyz123`
- **Expected**: Suggest homepage
- **Reason**: "Nothing relevant was found, redirected to the home URL"

### Test Case 3: External Link - Broken
- **Broken URL**: `https://external-site.com/404-page`
- **Expected**: Show manual edit suggestion
- **Message**: "This link is not working, either delete it or provide a new link"

### Test Case 4: Crawling Large Site
- **Scenario**: Site with 500+ pages
- **Expected**: Batch processing works, no timeouts, progress tracking updates

### Test Case 5: User Edits Suggestion
- **Scenario**: User changes suggested URL inline
- **Expected**: Database updated, change persists, can apply fix

---

## Development Notes

### Dependencies
- WordPress HTTP API for link testing
- WordPress Cron for background processing (optional)
- No external PHP libraries needed
- JavaScript: Vanilla JS or jQuery (already available in WordPress admin)

### Security Considerations
- ✅ Nonce verification for all AJAX requests
- ✅ Capability check: `manage_options`
- ✅ Sanitize all user inputs (especially edited URLs)
- ✅ Validate URLs before applying fixes
- ✅ Escape output in admin views

### Performance Optimizations
- Use transients for caching test results
- Implement pagination for large result sets
- Use database indexes for fast queries
- Lazy load link testing (don't test all at once)
- Background processing for large scans

---

## Future Enhancements (Phase 2+)

1. **Automatic Redirects**
   - Automatically create 301 redirects for broken internal links
   - Integrate with WordPress redirect functions

2. **Scheduled Scans**
   - Weekly/monthly automatic scans
   - Email reports of new broken links

3. **Link Context**
   - Show anchor text and surrounding content
   - Help users decide if suggestion is appropriate

4. **Broken Image Detection**
   - Extend to detect broken images (404 images)
   - Similar suggestion algorithm for images

5. **External Link Monitoring**
   - Monitor external links over time
   - Alert when previously working links break

---

## Success Criteria

- ✅ Crawler successfully extracts all links from website
- ✅ Link tester accurately identifies broken links (404, 4xx, 5xx)
- ✅ Algorithm provides relevant suggestions for internal links (>70% accuracy)
- ✅ UI is intuitive and easy to use
- ✅ Inline editing works smoothly
- ✅ Batch apply fixes works correctly
- ✅ No performance issues on sites with 500+ pages
- ✅ Module is completely self-contained (follows plugin rules)

---

**Document Version**: 1.0  
**Last Updated**: 2026-01-09  
**Status**: Planning Phase
