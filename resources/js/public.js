export function initPublicUI() {
    const stage = document.querySelector('[data-preview-motion]');
    if (!stage || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const animate = () => {
        stage.querySelector('.dashboard-preview').classList.add('animate__animated', 'animate__fadeInUp');
        stage.querySelectorAll('.float-label').forEach((badge) => badge.classList.add('motion-rotate'));
    };

    if (!('IntersectionObserver' in window)) {
        animate();
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) return;
        animate();
        observer.disconnect();
    }, { threshold: 0.18 });
    observer.observe(stage);
}
