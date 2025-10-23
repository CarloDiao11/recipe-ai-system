<!-- Meal Planner Section -->
<div class="content-section" id="mealPlannerSection">
    <div class="section-header">
        <i class="fas fa-calendar-alt"></i>
        <div>
            <h2>Weekly Meal Planner</h2>
            <p style="font-size: 0.9rem; opacity: 0.9;">Plan your meals for the week ahead</p>
        </div>
    </div>

    <?php
    // Organize meal plans by day and meal_type
    $mealPlanMap = [];
    foreach ($meal_plans as $plan) {
        $mealPlanMap[$plan['day_of_week']][$plan['meal_type']] = [
            'id' => $plan['id'],
            'recipe_id' => $plan['recipe_id'],
            'recipe_title' => $plan['recipe_title'] ?? 'Untitled Recipe'
        ];
    }

    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $meals = ['Breakfast', 'Lunch', 'Dinner'];
    ?>

    <div class="meal-planner-grid">
        <?php foreach ($days as $day): ?>
            <div class="day-card">
                <div class="day-header"><?= htmlspecialchars($day) ?></div>
                <?php foreach ($meals as $meal): ?>
                    <?php if (isset($mealPlanMap[$day][$meal])): ?>
                        <?php
                        $recipe = $mealPlanMap[$day][$meal];
                        $mealPlanId = $recipe['id'] ?? 0;
                        $recipeId = $recipe['recipe_id'] ?? 0;
                        $title = htmlspecialchars($recipe['recipe_title']);
                        ?>
                        <div class="meal-slot filled">
                            <div class="meal-content">
                                <span class="meal-type-label"><?= htmlspecialchars($meal) ?></span>
                                <span class="meal-recipe-title"><?= $title ?></span>
                            </div>
                            <div class="meal-actions">
                                <button class="meal-action-btn view-btn" onclick="viewRecipe(<?= $recipeId ?>)" title="View Recipe">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="meal-action-btn remove-btn" onclick="removeMeal(<?= $mealPlanId ?>, '<?= $day ?>', '<?= $meal ?>')" title="Remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="meal-slot empty" onclick="openAddMealModal('<?= $day ?>', '<?= $meal ?>')">
                            <i class="fas fa-plus-circle"></i>
                            <span><?= htmlspecialchars($meal) ?></span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add Meal Modal -->
<div id="addMealModal" class="meal-modal" style="display: none;">
    <div class="meal-modal-content">
        <div class="meal-modal-header">
            <h3><i class="fas fa-utensils"></i> Add Meal</h3>
            <button class="meal-modal-close" onclick="closeAddMealModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="meal-modal-body">
            <div class="meal-info">
                <p><strong>Day:</strong> <span id="selectedDay"></span></p>
                <p><strong>Meal Type:</strong> <span id="selectedMealType"></span></p>
            </div>
            <div class="recipe-search-container">
                <input type="text" id="recipeSearchInput" class="recipe-search-input" placeholder="Search recipes..." oninput="searchRecipes()">
            </div>
            <div class="recipe-list" id="recipeList">
                <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                    <i class="fas fa-search" style="font-size: 2rem; opacity: 0.5;"></i>
                    <p>Search for recipes to add to your meal plan</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Meal Planner Styles */
.meal-planner-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
}

.day-card {
    background: var(--bg-primary);
    border-radius: 12px;
    padding: 1rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.day-header {
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--accent-orange);
    margin-bottom: 1rem;
    text-align: center;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--border-color);
}

.meal-slot {
    padding: 0.75rem;
    margin: 0.5rem 0;
    border-radius: 8px;
    transition: all 0.3s ease;
    min-height: 60px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.meal-slot.empty {
    background: var(--bg-secondary);
    border: 2px dashed var(--border-color);
    cursor: pointer;
    align-items: center;
    text-align: center;
    color: var(--text-secondary);
}

.meal-slot.empty:hover {
    border-color: var(--accent-orange);
    background: rgba(255, 107, 53, 0.05);
    transform: translateY(-2px);
}

.meal-slot.empty i {
    font-size: 1.2rem;
    margin-bottom: 0.25rem;
}

.meal-slot.filled {
    background: linear-gradient(135deg, rgba(255, 107, 53, 0.1), rgba(74, 222, 128, 0.1));
    border: 1px solid var(--accent-green);
    position: relative;
}

.meal-content {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.meal-type-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.meal-recipe-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
    line-height: 1.3;
}

.meal-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.meal-action-btn {
    padding: 0.4rem 0.6rem;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    background: var(--bg-secondary);
    color: var(--text-primary);
}

.meal-action-btn:hover {
    transform: translateY(-2px);
}

.view-btn:hover {
    background: var(--accent-green);
    color: white;
}

.remove-btn:hover {
    background: #ef4444;
    color: white;
}

/* Modal Styles */
.meal-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6);
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.3s ease;
}

.meal-modal.active {
    display: flex !important;
}

.meal-modal-content {
    background: var(--bg-secondary);
    border-radius: 16px;
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    animation: slideUp 0.3s ease-out;
}

.meal-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 2px solid var(--border-color);
}

.meal-modal-header h3 {
    margin: 0;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.meal-modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-secondary);
    padding: 0.5rem;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.meal-modal-close:hover {
    background: var(--border-color);
    color: var(--text-primary);
}

.meal-modal-body {
    padding: 1.5rem;
    overflow-y: auto;
    flex: 1;
}

.meal-info {
    background: var(--bg-primary);
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.meal-info p {
    margin: 0.5rem 0;
    color: var(--text-primary);
}

.recipe-search-container {
    margin-bottom: 1rem;
}

.recipe-search-input {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    background: var(--bg-primary);
    color: var(--text-primary);
    font-size: 1rem;
}

.recipe-search-input:focus {
    outline: none;
    border-color: var(--accent-orange);
}

.recipe-list {
    max-height: 300px;
    overflow-y: auto;
}

.recipe-item {
    padding: 1rem;
    background: var(--bg-primary);
    border-radius: 8px;
    margin-bottom: 0.75rem;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 2px solid transparent;
}

.recipe-item:hover {
    border-color: var(--accent-orange);
    transform: translateX(5px);
}

.recipe-item-title {
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
}

.recipe-item-meta {
    font-size: 0.85rem;
    color: var(--text-secondary);
    display: flex;
    gap: 1rem;
}

@media (max-width: 768px) {
    .meal-planner-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
let selectedDay = '';
let selectedMealType = '';

function openAddMealModal(day, mealType) {
    selectedDay = day;
    selectedMealType = mealType;
    
    document.getElementById('selectedDay').textContent = day;
    document.getElementById('selectedMealType').textContent = mealType;
    document.getElementById('addMealModal').classList.add('active');
    document.getElementById('recipeSearchInput').value = '';
    
    // Load all recipes initially
    loadRecipes();
}

function closeAddMealModal() {
    document.getElementById('addMealModal').classList.remove('active');
}

function loadRecipes(searchQuery = '') {
    const recipeList = document.getElementById('recipeList');
    recipeList.innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading recipes...</div>';
    
    fetch('../../api/meal_planner.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_recipes&search=${encodeURIComponent(searchQuery)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.recipes.length > 0) {
            recipeList.innerHTML = data.recipes.map(recipe => `
                <div class="recipe-item" onclick="addMealToPlan(${recipe.id})">
                    <div class="recipe-item-title">${recipe.title}</div>
                    <div class="recipe-item-meta">
                        <span><i class="fas fa-clock"></i> ${recipe.time || 'N/A'}</span>
                        <span><i class="fas fa-signal"></i> ${recipe.difficulty || 'N/A'}</span>
                    </div>
                </div>
            `).join('');
        } else {
            recipeList.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-secondary);">No recipes found</div>';
        }
    })
    .catch(error => {
        console.error('Error loading recipes:', error);
        recipeList.innerHTML = '<div style="text-align: center; padding: 2rem; color: #ef4444;">Error loading recipes</div>';
    });
}

function searchRecipes() {
    const searchQuery = document.getElementById('recipeSearchInput').value;
    loadRecipes(searchQuery);
}

function addMealToPlan(recipeId) {
    const formData = new FormData();
    formData.append('action', 'add_meal');
    formData.append('recipe_id', recipeId);
    formData.append('day_of_week', selectedDay);
    formData.append('meal_type', selectedMealType);
    
    fetch('../../api/meal_planner.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeAddMealModal();
            location.reload(); // Reload to show updated meal plan
        } else {
            alert('Error: ' + (data.error || 'Failed to add meal'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while adding the meal');
    });
}

function removeMeal(mealPlanId, day, mealType) {
    if (!confirm(`Remove ${mealType} from ${day}?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'remove_meal');
    formData.append('meal_plan_id', mealPlanId);
    
    fetch('../../api/meal_planner.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload(); // Reload to show updated meal plan
        } else {
            alert('Error: ' + (data.error || 'Failed to remove meal'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while removing the meal');
    });
}

function viewRecipe(recipeId) {
    if (recipeId) {
        window.open(`view_recipe.php?id=${recipeId}`, '_blank');
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('addMealModal');
    if (event.target === modal) {
        closeAddMealModal();
    }
});
</script>