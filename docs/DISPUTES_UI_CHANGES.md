# Disputes System - UI Integration Changes

## Dashboard Navigation Updates

### Buyer Dashboard (`dashboard/buyer.php`)
**Sidebar Navigation:**
```
Before:
- Dashboard
- New Project
- Pipeline
- Spending
- Favorites

After:
- Dashboard
- New Project
- Pipeline
- Spending
- Favorites
+ Disputes        ← NEW: Links to /disputes/open_dispute.php
```

**Icon:** ⚖️  
**Color:** Matches primary theme

### Seller Dashboard (`dashboard/seller.php`)
**Sidebar Navigation:**
```
Before:
- Dashboard
- Opportunities
- Active Orders
- Earnings
- Profile

After:
- Dashboard
- Opportunities
- Active Orders
- Earnings
+ Disputes        ← NEW: Links to /disputes/open_dispute.php
- Profile
```

**Icon:** ⚖️  
**Placement:** Between Earnings and Profile

## Project View Updates (`dashboard/project_view.php`)

### Buyer Section - NEW: Disputed Escrow Alert
**Trigger:** When `escrow.status === 'disputed'`

**Visual Design:**
```
┌─────────────────────────────────────────────────────┐
│ ⚠️ DISPUTE OPEN                                      │
│ ═════════════════════════════════════════════════    │
│                                                      │
│ Status: Disputed - Awaiting Resolution              │
│ Amount: $X,XXX.XX                                   │
│                                                      │
│ [View Dispute]  or  [Open Dispute]                  │
└─────────────────────────────────────────────────────┘
```

**Styling:**
- Header background: #ff6b6b (Red)
- Border: 2px solid #ffc107 (Yellow)
- Status text: Red, bold
- Buttons: 50% width each, flexbox gap

**Buttons:**
- If dispute exists: "View Dispute" (btn-warning)
- If no dispute: "Open Dispute" (btn-danger)

### Seller Section - Disputed Status Warning
**Trigger:** When `escrow.status === 'disputed'` (seller role)

**Visual Design:**
```
┌──────────────────────────────────────────────────────┐
│ 🔒 ESCROW STATUS                                      │
│ ══════════════════════════════════════════════        │
│                                                       │
│ Status: [DISPUTED]                                   │
│ Amount: $X,XXX.XX                                    │
│                                                       │
│ ┌────────────────────────────────────────────────┐   │
│ │ ⚠️  This transaction is in dispute. Check the  │   │
│ │ dispute details for resolution.                │   │
│ └────────────────────────────────────────────────┘   │
│                                                       │
│ [View/Manage Dispute]                               │
└──────────────────────────────────────────────────────┘
```

**Styling:**
- Status badge: Red background (#ff6b6b)
- Alert box: Light red (#ffe0e0), dark red text (#c92a2a)
- Alert box padding: 10px, border-radius: 5px
- Button: 100% width, btn-warning style
- Icon: fas fa-exclamation-triangle

## Admin Dashboard Updates (`dashboard/admin.php`)

### New Cards Section
**Grid Layout:** Auto-fit columns (300px min, 1fr flex)

**Card 1: Manage Escrows**
```
┌─────────────────────────────────┐
│ 📋 Manage Escrows               │
│                                 │
│ [View all escrows]              │
└─────────────────────────────────┘
```

**Card 2: Active Disputes** ← NEW
```
┌─────────────────────────────────┐
│ ⚖️ Active Disputes              │
│                                 │
│ {open_count} (large, red)       │
│                                 │
│ [View all disputes]             │
└─────────────────────────────────┘
```

**Card 3: Total Disputes** ← NEW
```
┌─────────────────────────────────┐
│ 💰 Total Disputes               │
│                                 │
│ {total_count} (large, green)    │
│                                 │
│ [View dashboard]                │
└─────────────────────────────────┘
```

**Styling:**
- Card 1: Blue (#007bff) left border, light gray background
- Card 2: Yellow (#ffc107) left border, light yellow background
- Card 3: Green (#28a745) left border, light green background
- Count font-size: 2em, font-weight: bold

### New Table Section: Recent Open Disputes
**Condition:** Only shows if disputes exist

**Visual Design:**
```
┌──────────────────────────────────────────────────────┐
│ ⚠️ RECENT OPEN DISPUTES                              │
│ ═════════════════════════════════════════════════    │
│                                                      │
│ Dispute│ Project Title  │ Amount  │ Opened │ Action │
│─────────────────────────────────────────────────────│
│  #001  │ Mobile App Dev │ $5,000  │ Dec 18 │[Review]│
│  #002  │ Web Design     │ $3,500  │ Dec 17 │[Review]│
│  #003  │ Data Entry     │ $1,200  │ Dec 16 │[Review]│
└──────────────────────────────────────────────────────┘
```

**Table Columns:**
1. Dispute ID (Strong text)
2. Project Title
3. Amount (Bold, formatted currency)
4. Opened Date (Short format: "Mon DD, YYYY")
5. Action Button ("Review")

**Styling:**
- Header background: #e9ecef
- Column padding: 10px
- Border: 1px solid #dee2e6
- Button: Blue background, white text, small font
- Header background: Yellow/warning theme

## New Dispute Pages

### /disputes/open_dispute.php
**Layout:** Two-column (8-4 bootstrap grid)

**Left Column (Main Content):**
- H2: "Open Dispute"
- Alert messages (error/success)
- Card with form:
  - Select escrow dropdown (lists disputed escrows only)
  - Reason textarea (6 rows)
  - Buttons: "Open Dispute" (btn-danger), "Cancel" (btn-secondary)

**Right Column (Sidebar):**
- Info card (bg-light):
  - H5: "About Disputes"
  - Description text
  - Bullet list of features

**Color Scheme:**
- Primary button: Red (#dc3545) - "Open Dispute"
- Secondary button: Gray - "Cancel"
- Icon: fas fa-exclamation-circle

### /disputes/dispute_view.php
**Layout:** Two-column responsive (main 9 cols, sidebar 3 cols)

**Main Content Column:**

1. **Dispute Header Card** (bg-warning text-dark)
   - Dispute ID with status badge
   - Grid: Project, Amount, Opener, Participants
   - Initial reason (quoted box)

2. **Messages Section**
   - Scrollable (400px max height)
   - Message boxes: light gray background
   - Username + timestamp + message content
   - Chronological order

3. **Add Message Form**
   - Textarea (4 rows)
   - "Post Message" button

4. **Evidence Section**
   - File list (if any)
   - Each file: Name, uploader, date, download button
   - Upload form:
     - File input
     - Submit button (btn-success)
     - Help text with size/type limits

**Sidebar Column (Admin Only):**

**Admin Resolution Panel** (border-danger)
- Header: bg-danger, white text
- H5: "⚖️ Admin Resolution"
- Form:
  - Action dropdown (refund_buyer/release_to_seller/split)
  - Resolution notes textarea
  - Split ratio input (shows only if split selected)
  - Submit button (btn-danger, full width)

**JavaScript:**
- Toggle split ratio input visibility based on action selection

## Color & Styling Reference

### Status Colors
| Status | Color | Usage |
|--------|-------|-------|
| disputed | #ff6b6b (Red) | Badge, header, warning text |
| open (dispute) | #ff6b6b (Red) | Badge |
| resolved | #28a745 (Green) | Badge, success messages |
| pending | #6c757d (Gray) | Badge, neutral status |

### UI Component Colors
| Component | Color | Usage |
|-----------|-------|-------|
| Alert/Warning | #ffc107 (Yellow) | Border, background tints |
| Error/Critical | #dc3545 (Red Danger) | Buttons, critical alerts |
| Info | #0dcaf0 (Info Blue) | Info messages, count badges |
| Success | #198754 (Success Green) | Confirmations, resolved badges |
| Action Primary | #007bff (Primary Blue) | Primary buttons |

### Spacing
- Card padding: 20px
- Margin between sections: 30px
- Message box padding: 10px inside, 10px margin between
- Form gaps: 15px between elements
- Button gap: 10px

## Responsive Design

### Mobile (< 768px)
- Two-column layouts → Stack vertically
- Project view dispute section: Full width
- Admin panel moves below messages (on mobile)
- Table → Horizontal scroll or collapsed cards

### Tablet (768px - 1024px)
- Two-column layouts maintained
- Adjust padding/margins for smaller screens
- Sidebar may condense

### Desktop (> 1024px)
- Full multi-column layouts
- Optimal spacing maintained
- All features visible without scroll

## Accessibility Features

- Semantic HTML: `<h1>`, `<table>`, `<form>` tags used correctly
- Color not only indicator: Icons + text for all status/warnings
- Form labels tied to inputs via `<label for="id">`
- Buttons have clear action text
- Timestamps in human-readable format
- Error messages clear and actionable

## Icon Usage

| Icon | Meaning |
|------|---------|
| 📊 Dashboard | Navigation |
| ⚖️ Disputes | Dispute section |
| ➕ New Project | Create action |
| ⚠️ Warning | Attention needed |
| ⚡ Active | In progress |
| 💰 Money | Payment/amount |
| 🔒 Lock | Security/escrow |
| 💬 Message | Communication |
| 📎 Evidence | File/attachment |
| 👥 People | User/participant |
| ✓ Check | Confirmed/success |

## Navigation Flow Summary

```
Entry Points:
1. Dashboard Sidebar → Click "⚖️ Disputes"
2. Project View → Click dispute button
3. Admin Dashboard → Click dispute card or table row

From Entry:
- Buyer/Seller: → /disputes/open_dispute.php (list)
                → /disputes/dispute_view.php (details)
                → Add message form
                → Upload evidence form

- Admin: → /disputes/index.php (all disputes)
        → /disputes/dispute_view.php (single dispute)
        → Resolve form
        → Return to list

Exit Points:
- Dashboard link in header/footer
- Project view link
- Back button in browser
```

---
**Last Updated:** December 18, 2025  
**Status:** ✅ Ready for Implementation
