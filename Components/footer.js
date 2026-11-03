/**
 * Componente Footer para renderizado dinámico en JavaScript
 */
function renderFooter(targetId = 'footer-container') {
    const container = document.getElementById(targetId);
    if (!container) return;

    const year = new Date().getFullYear();
    container.innerHTML = `
    <footer class="py-4 text-center text-secondary small border-top border-secondary border-opacity-25 mt-auto">
        <div class="container">
            <p class="mb-0">&copy; ${year} Sophos Core - Sistema Integral de Gestión Educativa.</p>
        </div>
    </footer>
    `;
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('footer-container')) {
        renderFooter('footer-container');
    }
});
