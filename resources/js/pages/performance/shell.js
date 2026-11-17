// Room left for the sticky mobile app bar, so the compact header appears before the page header hides under it.
const HEADER_OFFSET_PX = 72;

/**
 * Dashboard frame: shows the compact header once the page header scrolls out of view and opens
 * the Filter panel from it. The filter controls themselves belong to FIX-04-FE-01.
 */
export default function dashboardShell() {
    let observer = null;

    return {
        compact: false,
        filterPanelOpen: false,

        init() {
            if (!('IntersectionObserver' in window) || !this.$refs.pageHeader) return;

            observer = new IntersectionObserver(([entry]) => {
                this.compact = !entry.isIntersecting;
            }, { rootMargin: `-${HEADER_OFFSET_PX}px 0px 0px 0px` });
            observer.observe(this.$refs.pageHeader);
        },

        destroy() {
            observer?.disconnect();
            observer = null;
        },

        openFilterPanel() {
            this.filterPanelOpen = true;
        },

        closeFilterPanel() {
            this.filterPanelOpen = false;
        },
    };
}
