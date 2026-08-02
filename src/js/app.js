/**
 * FRESSI — Interactive Client JavaScript Application
 */

document.addEventListener('DOMContentLoaded', () => {
  // Sample Recipe Database
  const recipes = [
    {
      id: 1,
      title: "Cremige Knoblauch-Pasta mit Trüffel-Note",
      category: "comfort",
      time: "20 Min.",
      difficulty: "Einfach",
      calories: "450 kcal",
      icon: "🍝",
      desc: "Zarte Fettuccine in samtiger Parmesansoße verfeinert mit frischem Knoblauch und feinem Trüffelöl.",
      ingredients: ["250g Fettuccine", "3 Zehen Knoblauch", "100ml Schlagsahne", "50g Parmesan", "1 EL Trüffelöl", "Frische Petersilie"],
      steps: ["Pasta in reichlich Salzwasser al dente kochen.", "Knoblauch fein hacken und in Olivenöl andünsten.", "Sahne und frisch geriebenen Parmesan dazugeben.", "Pasta unter die Soße heben und mit Trüffelöl beträufeln."]
    },
    {
      id: 2,
      title: "Bunte Vegan Rainbow Buddha Bowl",
      category: "vegan",
      time: "15 Min.",
      difficulty: "Sehr Einfach",
      calories: "320 kcal",
      icon: "🥗",
      desc: "Nährstoffreiche Schüssel mit gerösteten Kichererbsen, frischer Avocado, Quinoa und Erdnuss-Dressing.",
      ingredients: ["150g gekochte Quinoa", "1/2 Avocado", "100g Kichererbsen", "Frischer Spinat", "Rotkohl", "2 EL Erdnussmus"],
      steps: ["Quinoa als Basis in der Bowl anrichten.", "Avocado schneiden, Rotkohl hobeln.", "Kichererbsen knusprig anbraten.", "Erdnussmus mit Limettensaft verrühren und drüber geben."]
    },
    {
      id: 3,
      title: "Knuspriger Lachs auf Spargel-Bett",
      category: "lowcarb",
      time: "25 Min.",
      difficulty: "Mittel",
      calories: "380 kcal",
      icon: "🐟",
      desc: "Saftiges Lachsfilet auf der Haut gebraten mit frischem grünem Spargel und Dill-Zitronen-Butter.",
      ingredients: ["2 Lachsfilets", "250g grüner Spargel", "20g Butter", "1 Zitrone", "Frischer Dill", "Meersalz & Pfeffer"],
      steps: ["Spargelenden abbrechen und Spargel 5 Min. anbraten.", "Lachsfilets auf der Hautseite 4 Min. knusprig braten, wenden.", "Butter und Zitronensaft dazugeben.", "Mit frischem Dill servieren."]
    },
    {
      id: 4,
      title: "Smashed Avocado & Poached Egg Toast",
      category: "quick",
      time: "10 Min.",
      difficulty: "Einfach",
      calories: "290 kcal",
      icon: "🥑",
      desc: "Röstbrot mit cremiger Avocado, perfekt pochiertem Ei und feinen Chiliflocken.",
      ingredients: ["2 Scheiben Sauerteigbrot", "1 reife Avocado", "2 bio Eier", "1 TL Limettensaft", "Chiliflocken"],
      steps: ["Sauerteigbrot goldbraun toasten.", "Avocado mit Gabel zerdrücken, Limettensaft und Salz zufügen.", "Eier in leicht siedendem Essigwasser 3 Min. pochieren.", "Toast bestreichen, Ei auflegen und mit Chili bestreuen."]
    },
    {
      id: 5,
      title: "Deftiger Gourmet Smash Burger",
      category: "comfort",
      time: "20 Min.",
      difficulty: "Mittel",
      calories: "620 kcal",
      icon: "🍔",
      desc: "Knusprig gesmashtes Rindfleisch-Patty mit geschmolzenem Cheddar, Caramelized Onions und Fressi Special Sauce.",
      ingredients: ["200g Rinderhack", "2 Scheiben Cheddar", "1 Brioche Bun", "1 Zwiebel", "Fressi Burger-Sauce"],
      steps: ["Zwiebeln langsam braun karamellisieren.", "Hackfleisch zu Kugeln formen und in der heißen Pfanne flach smashen.", "Cheddar schmelzen lassen und Bun anrösten.", "Burger schichten und sofort genießen."]
    },
    {
      id: 6,
      title: "Waldpilz-Risotto mit frischem Thymian",
      category: "vegetarian",
      time: "30 Min.",
      difficulty: "Mittel",
      calories: "410 kcal",
      icon: "🍲",
      desc: "Cremiges Steinpilz-Risotto langsam mit Gemüsebrühe aufgegossen und mit Parmesan verfeinert.",
      ingredients: ["200g Risotto-Reis", "150g Gemischte Pilze", "100ml Weißwein", "500ml Gemüsebrühe", "Thymian"],
      steps: ["Pilze anbraten und beiseitestellen.", "Reis in Olivenöl glasig dünsten, mit Weißwein ablöschen.", "Nach und nach warme Brühe unter Rühren zufügen.", "Pilze und Parmesan am Ende unterheben."]
    }
  ];

  // State Management
  let favorites = JSON.parse(localStorage.getItem('fressi_favs') || '[]');
  let activeCategory = 'all';

  // Elements
  const recipeGrid = document.getElementById('recipe-grid');
  const searchInput = document.getElementById('search-input');
  const categoryChips = document.querySelectorAll('.chip-btn');
  const favCountBadge = document.getElementById('fav-count');
  const spinBtn = document.getElementById('spin-btn');
  const randomResult = document.getElementById('randomizer-result');
  const themeToggle = document.getElementById('theme-toggle');
  const modalOverlay = document.getElementById('modal-overlay');
  const modalBody = document.getElementById('modal-body');
  const modalClose = document.getElementById('modal-close');
  const contactForm = document.getElementById('contact-form');
  const toastContainer = document.getElementById('toast-container');

  // Initial Render
  updateFavBadge();
  renderRecipes(recipes);

  // Theme Management
  const currentTheme = localStorage.getItem('fressi_theme') || 'dark';
  document.documentElement.setAttribute('data-theme', currentTheme);
  updateThemeIcon(currentTheme);

  themeToggle.addEventListener('click', () => {
    const newTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('fressi_theme', newTheme);
    updateThemeIcon(newTheme);
    showToast(`Design-Modus auf ${newTheme === 'dark' ? 'Dunkel' : 'Hell'} gewechselt!`);
  });

  function updateThemeIcon(theme) {
    themeToggle.innerHTML = theme === 'dark' ? '🌙' : '☀️';
  }

  // Filter & Search
  function filterRecipes() {
    const query = searchInput.value.toLowerCase().trim();
    const filtered = recipes.filter(r => {
      const matchesCat = activeCategory === 'all' || r.category === activeCategory;
      const matchesQuery = r.title.toLowerCase().includes(query) || r.desc.toLowerCase().includes(query);
      return matchesCat && matchesQuery;
    });
    renderRecipes(filtered);
  }

  searchInput.addEventListener('input', filterRecipes);

  categoryChips.forEach(chip => {
    chip.addEventListener('click', () => {
      categoryChips.forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      activeCategory = chip.dataset.category;
      filterRecipes();
    });
  });

  // Render Recipe Cards
  function renderRecipes(items) {
    if (!recipeGrid) return;
    
    if (items.length === 0) {
      recipeGrid.innerHTML = `
        <div style="grid-column: 1/-1; text-align: center; padding: 3rem; color: var(--text-muted);">
          <span style="font-size: 3rem;">🔍</span>
          <h3 style="margin-top: 1rem;">Keine Rezepte gefunden</h3>
          <p>Versuche einen anderen Suchbegriff oder Filter!</p>
        </div>
      `;
      return;
    }

    recipeGrid.innerHTML = items.map(recipe => {
      const isFav = favorites.includes(recipe.id);
      return `
        <article class="recipe-card glass-panel" data-id="${recipe.id}">
          <div class="card-media">
            <div class="card-img-placeholder">${recipe.icon}</div>
            <span class="card-badge">${recipe.category.toUpperCase()}</span>
            <button class="card-fav-btn ${isFav ? 'active' : ''}" data-id="${recipe.id}" aria-label="Favorit speichern">
              ${isFav ? '❤️' : '🤍'}
            </button>
          </div>
          <div class="card-body">
            <h3 class="recipe-title">${recipe.title}</h3>
            <p class="recipe-desc">${recipe.desc}</p>
            <div class="recipe-meta">
              <span class="meta-item">⏱️ ${recipe.time}</span>
              <span class="meta-item">🔥 ${recipe.calories}</span>
              <span class="meta-item">📊 ${recipe.difficulty}</span>
            </div>
          </div>
        </article>
      `;
    }).join('');

    // Attach Event Listeners to rendered cards
    document.querySelectorAll('.card-fav-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const id = parseInt(btn.dataset.id);
        toggleFavorite(id);
      });
    });

    document.querySelectorAll('.recipe-card').forEach(card => {
      card.addEventListener('click', () => {
        const id = parseInt(card.dataset.id);
        openRecipeModal(id);
      });
    });
  }

  // Favorites Toggle
  function toggleFavorite(id) {
    if (favorites.includes(id)) {
      favorites = favorites.filter(favId => favId !== id);
      showToast('Rezept aus deinen Favoriten entfernt');
    } else {
      favorites.push(id);
      showToast('Rezept zu deinen Favoriten hinzugefügt! ❤️');
    }
    localStorage.setItem('fressi_favs', JSON.stringify(favorites));
    updateFavBadge();
    filterRecipes();
  }

  function updateFavBadge() {
    if (favCountBadge) {
      favCountBadge.textContent = favorites.length;
    }
  }

  // Favorites Filter Button
  const favToggleBtn = document.getElementById('fav-toggle-btn');
  if (favToggleBtn) {
    favToggleBtn.addEventListener('click', () => {
      const favRecipes = recipes.filter(r => favorites.includes(r.id));
      renderRecipes(favRecipes);
      showToast(`Zeige ${favRecipes.length} gespeicherte Favoriten`);
    });
  }

  // Recipe Modal
  function openRecipeModal(id) {
    const recipe = recipes.find(r => r.id === id);
    if (!recipe || !modalOverlay || !modalBody) return;

    modalBody.innerHTML = `
      <div style="text-align: center; margin-bottom: 1.5rem;">
        <span style="font-size: 4rem;">${recipe.icon}</span>
        <h2 style="font-size: 1.8rem; margin-top: 0.5rem;">${recipe.title}</h2>
        <div style="display: flex; justify-content: center; gap: 1rem; margin-top: 0.8rem; color: var(--text-secondary); font-size: 0.9rem;">
          <span>⏱️ ${recipe.time}</span>
          <span>🔥 ${recipe.calories}</span>
          <span>📊 ${recipe.difficulty}</span>
        </div>
      </div>
      <p style="color: var(--text-secondary); margin-bottom: 1.5rem; text-align: center;">${recipe.desc}</p>
      
      <h3 style="font-size: 1.1rem; margin-bottom: 0.8rem; color: var(--accent-orange);">Zutaten:</h3>
      <ul style="margin-left: 1.2rem; margin-bottom: 1.5rem; color: var(--text-primary);">
        ${recipe.ingredients.map(ing => `<li>${ing}</li>`).join('')}
      </ul>

      <h3 style="font-size: 1.1rem; margin-bottom: 0.8rem; color: var(--accent-orange);">Zubereitung:</h3>
      <ol style="margin-left: 1.2rem; color: var(--text-primary);">
        ${recipe.steps.map(step => `<li style="margin-bottom: 0.5rem;">${step}</li>`).join('')}
      </ol>
    `;

    modalOverlay.classList.add('active');
  }

  if (modalClose) {
    modalClose.addEventListener('click', () => modalOverlay.classList.remove('active'));
  }

  if (modalOverlay) {
    modalOverlay.addEventListener('click', (e) => {
      if (e.target === modalOverlay) modalOverlay.classList.remove('active');
    });
  }

  // Meal Randomizer "Was koche ich heute?"
  if (spinBtn && randomResult) {
    spinBtn.addEventListener('click', () => {
      spinBtn.disabled = true;
      randomResult.innerHTML = `<span>🎲 Mischst Gerichte...</span>`;
      
      let counter = 0;
      const interval = setInterval(() => {
        const temp = recipes[Math.floor(Math.random() * recipes.length)];
        randomResult.innerHTML = `<span style="font-size: 1.8rem;">${temp.icon} ${temp.title}</span>`;
        counter++;
        if (counter > 12) {
          clearInterval(interval);
          const finalMeal = recipes[Math.floor(Math.random() * recipes.length)];
          randomResult.innerHTML = `
            <div class="pop-anim">
              <span style="font-size: 2.2rem; display: block; margin-bottom: 0.4rem;">${finalMeal.icon}</span>
              <h4 style="font-size: 1.2rem;">${finalMeal.title}</h4>
              <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.2rem;">Klick für Details (${finalMeal.time})</p>
            </div>
          `;
          randomResult.style.cursor = 'pointer';
          randomResult.onclick = () => openRecipeModal(finalMeal.id);
          spinBtn.disabled = false;
          showToast(`Dein Gericht: ${finalMeal.title}! 🎉`);
        }
      }, 100);
    });
  }

  // Contact Form AJAX Handler
  if (contactForm) {
    contactForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(contactForm);
      formData.append('ajax_contact', '1');

      try {
        const response = await fetch('index.php', {
          method: 'POST',
          body: formData
        });
        const result = await response.json();
        
        if (result.status === 'success') {
          showToast('Danke für deine Nachricht! Wir antworten schnellstmöglich. 🚀');
          contactForm.reset();
        } else {
          showToast(result.message || 'Fehler beim Senden.');
        }
      } catch (err) {
        showToast('Vielen Dank! Deine Nachricht wurde empfangen.');
        contactForm.reset();
      }
    });
  }

  // Toast Notification Helper
  function showToast(message) {
    if (!toastContainer) return;
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    toastContainer.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(100%)';
      toast.style.transition = 'all 0.3s ease-out';
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }
});
