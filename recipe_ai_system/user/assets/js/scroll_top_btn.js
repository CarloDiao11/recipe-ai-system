// === Scroll to Top ===
        window.addEventListener('scroll', function() {
            const scrollBtn = document.getElementById('scrollTopBtn');
            const chatPopup = document.getElementById('chatPopup');
            
            if (window.pageYOffset > 300) {
                scrollBtn.classList.add('visible');
                if (chatPopup.classList.contains('active')) {
                    scrollBtn.classList.add('has-chat');
                }
            } else {
                scrollBtn.classList.remove('visible');
            }
        });

        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }