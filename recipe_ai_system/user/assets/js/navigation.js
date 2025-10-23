// === Navigation ===
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
                
                const href = this.getAttribute('href');
                document.querySelectorAll(`.nav-link[href="${href}"]`).forEach(l => l.classList.add('active'));
                
                document.querySelectorAll('.content-section').forEach(section => {
                    section.classList.remove('active');
                });
                const target = href.substring(1);
                if (target === 'ai-generator') {
                    document.getElementById('aiSection').classList.add('active');
                } else if (target === 'chat') {
                    document.getElementById('chatSection').classList.add('active');
                } else if (target === 'meal-planner') {
                    document.getElementById('mealPlannerSection').classList.add('active');
                } else if (target === 'community') {
                    document.getElementById('communitySection').classList.add('active');
                }
            });
        });