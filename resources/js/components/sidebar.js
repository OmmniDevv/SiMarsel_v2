import { showToast } from './toast';
import { initLogout } from './logout';

export default function sidebar() {
    let viewport;
    let onViewportChange;

    return {
        open: false,
        desktop: false,

        init() {
            initLogout();
            viewport = window.matchMedia('(min-width: 1024px)');
            this.desktop = viewport.matches;
            onViewportChange = () => {
                this.desktop = viewport.matches;
                this.open = false;
            };
            viewport.addEventListener('change', onViewportChange);
        },

        destroy() {
            viewport?.removeEventListener('change', onViewportChange);
        },

        soon(label) {
            showToast({ type: 'info', title: 'Fitur Belum Tersedia', text: `Menu "${label}" belum dibuat.` });
        },

        locked(label) {
            showToast({ type: 'info', title: 'Akses Terbatas', text: `Anda tidak memiliki akses ke menu "${label}".` });
        },
    };
}
