document.addEventListener('DOMContentLoaded', () => {
    // Tooltips initialization (configurado con 'hover' para evitar que se queden pegados al hacer clic)
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl, {
        trigger: 'hover'
    }));

    // Sidebar Collapse / Expand Logic
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');

    if (sidebar && sidebarToggle) {
        // Recuperar preferencia de usuario
        const isCollapsed = localStorage.getItem('sophos_sidebar_collapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
            sidebarToggle.classList.add('active');
        }

        sidebarToggle.addEventListener('click', (e) => {
            e.preventDefault();
            sidebar.classList.toggle('collapsed');
            sidebarToggle.classList.toggle('active');
            
            // Ocultar tooltip inmediatamente y quitar foco para que no permanezca activo
            const tooltipInstance = bootstrap.Tooltip.getInstance(sidebarToggle);
            if (tooltipInstance) {
                tooltipInstance.hide();
            }
            sidebarToggle.blur();
            
            const currentState = sidebar.classList.contains('collapsed');
            localStorage.setItem('sophos_sidebar_collapsed', currentState);
        });
    }

    // SPA Logic for Sidebars
    const navLinks = document.querySelectorAll('.sidebar .nav-link');
    const sections = document.querySelectorAll('.spa-section');

    if(navLinks.length > 0 && sections.length > 0) {
        navLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                
                // Remove active from all links
                navLinks.forEach(l => l.classList.remove('active'));
                
                // Add active to clicked
                link.classList.add('active');

                // Hide all sections
                sections.forEach(sec => sec.classList.remove('active'));

                // Show target section
                const targetId = link.getAttribute('data-target');
                const targetSec = document.getElementById(targetId);
                if(targetSec) {
                    targetSec.classList.add('active');
                }
            });
        });
    }
    // Login Form Navigation conectado a la Base de Datos MySQL (sophos_core)
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = (document.getElementById('loginEmail')?.value || '').trim();
            const password = (document.getElementById('loginPassword')?.value || '').trim();
            const alertBox = document.getElementById('loginAlert');
            const submitBtn = loginForm.querySelector('button[type="submit"]');

            if (alertBox) alertBox.classList.add('d-none');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Verificando...';
            }

            const inViews = window.location.pathname.includes('/Views/') || window.location.pathname.includes('/views/');
            const apiPath = inViews ? '../../api/login.php' : 'api/login.php';

            try {
                const response = await fetch(apiPath, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, password })
                });
                const data = await response.json();

                if (data.success) {
                    sessionStorage.setItem('sophos_session', JSON.stringify(data.user));
                    let dest = data.redirect_url;
                    if (inViews) {
                        dest = dest.replace('Views/', '');
                    }
                    window.location.href = dest;
                } else {
                    if (alertBox) {
                        alertBox.textContent = data.error || 'Credenciales incorrectas.';
                        alertBox.classList.remove('d-none');
                    } else {
                        alert(data.error || 'Credenciales incorrectas.');
                    }
                }
            } catch (err) {
                console.error("Error al autenticar:", err);
                if (alertBox) {
                    alertBox.textContent = 'Error de conexión con el servidor.';
                    alertBox.classList.remove('d-none');
                }
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Iniciar Sesión <i class="bi bi-arrow-right ms-1"></i>';
                }
            }
        });
    }

    // Modal trigger safeguard (garantiza apertura de loginModal)
    document.querySelectorAll('[data-bs-target="#loginModal"]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const modalEl = document.getElementById('loginModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.show();
            }
        });
    });

    // Visualizar / Ocultar Contraseña
    const toggleLoginBtn = document.getElementById('toggleLoginPassword');
    const loginPassInput = document.getElementById('loginPassword');
    const toggleLoginIcon = document.getElementById('toggleLoginIcon');

    if (toggleLoginBtn && loginPassInput) {
        toggleLoginBtn.addEventListener('click', () => {
            const isPassword = loginPassInput.type === 'password';
            loginPassInput.type = isPassword ? 'text' : 'password';
            if (toggleLoginIcon) {
                toggleLoginIcon.classList.toggle('bi-eye', !isPassword);
                toggleLoginIcon.classList.toggle('bi-eye-slash', isPassword);
            }
        });
    }
});
