const FOCUSABLE = 'button, [href], input, select, textarea, summary, [tabindex]:not([tabindex="-1"])';

export function createDialogManager(doc = document) {
    const stack = [];
    const background = new Map();
    let overflow;
    const visible = (element) => !element.disabled && !element.closest('[inert]') && element.getClientRects().length > 0;
    const controls = (element) => [...element.querySelectorAll(FOCUSABLE)].filter(visible);
    const top = () => stack.at(-1);

    function focus(element) {
        const initial = element.querySelector('[data-dialog-initial]');
        (initial && visible(initial) ? initial : controls(element)[0] ?? element).focus();
    }

    function sync() {
        background.forEach((inert, element) => { element.inert = inert; });
        background.clear();
        if (!top()) return;

        let branch = top().element;
        while (branch.parentElement) {
            for (const sibling of branch.parentElement.children) {
                if (sibling === branch) continue;
                background.set(sibling, sibling.inert);
                sibling.inert = true;
            }
            branch = branch.parentElement;
            if (branch === doc.body) break;
        }
    }

    function keydown(event) {
        if (event.key !== 'Tab' || !top()) return;
        const { element } = top();
        const items = controls(element);
        const first = items[0];
        const last = items.at(-1);
        if (!items.length || !element.contains(doc.activeElement)
            || (event.shiftKey && doc.activeElement === first)
            || (!event.shiftKey && doc.activeElement === last)) {
            event.preventDefault();
            (event.shiftKey ? last : first)?.focus();
            if (!items.length) element.focus();
        }
    }

    function focusin(event) {
        if (top() && !top().element.contains(event.target)) focus(top().element);
    }

    return {
        open(element) {
            if (stack.some((item) => item.element === element)) return;
            if (!stack.length) {
                overflow = doc.body.style.overflow;
                doc.body.style.overflow = 'hidden';
                doc.addEventListener('keydown', keydown, true);
                doc.addEventListener('focusin', focusin, true);
            }
            stack.push({ element, opener: doc.activeElement });
            sync();
            focus(element);
        },

        close(element) {
            const index = stack.findIndex((item) => item.element === element);
            if (index < 0) return;
            const wasTop = index === stack.length - 1;
            const [{ opener }] = stack.splice(index, 1);
            // If a lower dialog disappears first, restore to its original trigger after the top closes.
            stack.forEach((item) => {
                if (element.contains(item.opener)) item.opener = opener;
            });
            sync();
            if (!stack.length) {
                doc.body.style.overflow = overflow;
                doc.removeEventListener('keydown', keydown, true);
                doc.removeEventListener('focusin', focusin, true);
            }
            if (!wasTop) return;
            if (opener?.isConnected && visible(opener) && (!top() || top().element.contains(opener))) opener.focus();
            else if (top()) focus(top().element);
        },
    };
}

export default function registerDialog(Alpine) {
    const manager = createDialogManager();
    Alpine.directive('dialog', (element, { expression }, { evaluateLater, effect, cleanup }) => {
        element.setAttribute('tabindex', '-1');
        const evaluate = evaluateLater(expression);
        let active = false;
        effect(() => evaluate((value) => {
            active = Boolean(value);
            if (!active) manager.close(element);
            else Alpine.nextTick(() => { if (active && element.isConnected) manager.open(element); });
        }));
        cleanup(() => { active = false; manager.close(element); });
    });
}
