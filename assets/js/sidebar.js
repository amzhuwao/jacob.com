/**
 * Responsive Sidebar Navigation
 * Handles mobile overlay, desktop persistence, keyboard navigation
 */

// Sidebar state management
const SidebarManager = {
    sidebar: null,
    overlay: null,
    toggleBtn: null,
    isOpen: false,
    isMobile: false,

    init() {
        this.sidebar = document.querySelector('.sidebar');
        this.overlay = document.getElementById('sidebar-overlay');
        this.toggleBtn = document.querySelector('.toggle-sidebar');
        
        if (!this.sidebar) return;

        // Create overlay if it doesn't exist
        if (!this.overlay) {
            this.createOverlay();
        }

        // Set up event listeners
        this.setupEventListeners();
        
        // Handle responsive behavior
        this.handleResize();
        window.addEventListener('resize', () => this.handleResize());

        // Set active page
        this.setActivePage();

        // Keyboard accessibility
        this.setupKeyboardNav();
    },

    createOverlay() {
        this.overlay = document.createElement('div');
        this.overlay.id = 'sidebar-overlay';
        this.overlay.className = 'sidebar-overlay';
        document.body.appendChild(this.overlay);
    },

    setupEventListeners() {
        // Toggle button
        if (this.toggleBtn) {
            this.toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggle();
            });
        }

        // Overlay click to close
        if (this.overlay) {
            this.overlay.addEventListener('click', () => this.close());
        }

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen && this.isMobile) {
                this.close();
            }
        });

        // Sidebar links - close on mobile after click
        const sidebarLinks = this.sidebar.querySelectorAll('.sidebar-nav a');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (this.isMobile) {
                    this.close();
                }
            });
        });
    },

    handleResize() {
        const wasMobile = this.isMobile;
        this.isMobile = window.innerWidth < 1024;

        // If switched from mobile to desktop
        if (wasMobile && !this.isMobile) {
            this.close();
            this.sidebar.classList.remove('mobile-hidden');
            document.body.style.overflow = '';
        }

        // If on mobile, hide sidebar by default
        if (this.isMobile && !wasMobile) {
            this.sidebar.classList.add('mobile-hidden');
        }
    },

    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    },

    open() {
        this.isOpen = true;
        this.sidebar.classList.add('open');
        this.sidebar.classList.remove('mobile-hidden');
        
        if (this.isMobile) {
            this.overlay.classList.add('active');
            // Lock scroll on body
            document.body.style.overflow = 'hidden';
            
            // Focus first link for accessibility
            const firstLink = this.sidebar.querySelector('.sidebar-nav a');
            if (firstLink) {
                setTimeout(() => firstLink.focus(), 100);
            }
        }
    },

    close() {
        this.isOpen = false;
        this.sidebar.classList.remove('open');
        
        if (this.isMobile) {
            this.overlay.classList.remove('active');
            this.sidebar.classList.add('mobile-hidden');
            // Unlock scroll
            document.body.style.overflow = '';
        }
    },

    setActivePage() {
        const currentPath = window.location.pathname;
        const currentHash = window.location.hash;
        const links = this.sidebar.querySelectorAll('.sidebar-nav a');

        links.forEach(link => {
            link.classList.remove('active');
            
            const linkPath = new URL(link.href).pathname;
            const linkHash = new URL(link.href).hash;

            // Exact match with hash
            if (linkPath === currentPath && linkHash === currentHash) {
                link.classList.add('active');
            }
            // Exact path match (no hash)
            else if (linkPath === currentPath && !currentHash && !linkHash) {
                link.classList.add('active');
            }
            // Path match only
            else if (linkPath === currentPath && !linkHash) {
                link.classList.add('active');
            }
        });
    },

    setupKeyboardNav() {
        const links = this.sidebar.querySelectorAll('.sidebar-nav a');
        
        links.forEach((link, index) => {
            link.addEventListener('keydown', (e) => {
                // Arrow down - next link
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const nextLink = links[index + 1];
                    if (nextLink) nextLink.focus();
                }
                
                // Arrow up - previous link
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prevLink = links[index - 1];
                    if (prevLink) prevLink.focus();
                }

                // Home - first link
                if (e.key === 'Home') {
                    e.preventDefault();
                    links[0].focus();
                }

                // End - last link
                if (e.key === 'End') {
                    e.preventDefault();
                    links[links.length - 1].focus();
                }
            });
        });
    }
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => SidebarManager.init());
} else {
    SidebarManager.init();
}

// Legacy function for backwards compatibility
function toggleSidebar() {
    SidebarManager.toggle();
}
