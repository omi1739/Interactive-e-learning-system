            </main>
            </div>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
            <script>
                // Dark Mode Toggle
                const darkModeToggle = document.getElementById('darkModeToggle');
                const body = document.body;

                // Check for saved dark mode preference
                if (localStorage.getItem('darkMode') === 'enabled') {
                    body.classList.add('dark-mode');
                    darkModeToggle.innerHTML = '<i class="fas fa-sun"></i> Light Mode';
                }

                darkModeToggle.addEventListener('click', () => {
                    if (body.classList.contains('dark-mode')) {
                        body.classList.remove('dark-mode');
                        localStorage.setItem('darkMode', 'disabled');
                        darkModeToggle.innerHTML = '<i class="fas fa-moon"></i> Dark Mode';
                    } else {
                        body.classList.add('dark-mode');
                        localStorage.setItem('darkMode', 'enabled');
                        darkModeToggle.innerHTML = '<i class="fas fa-sun"></i> Light Mode';
                    }
                });

                // Auto-dismiss alerts after 5 seconds
                setTimeout(() => {
                    const alerts = document.querySelectorAll('.alert');
                    alerts.forEach(alert => {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    });
                }, 5000);
            </script>
            </body>

            </html>