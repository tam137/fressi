/**
 * FRESSI — Automatic Photo Capture, Structured Analysis & Refinement Loop Application
 */

document.addEventListener('DOMContentLoaded', () => {
  // Set default body mode class
  document.body.classList.add('is-photo-mode');

  // Theme Management
  const themeToggle = document.getElementById('theme-toggle');
  const currentTheme = localStorage.getItem('fressi_theme') || 'light';
  document.documentElement.setAttribute('data-theme', currentTheme);

  if (themeToggle) {
    themeToggle.textContent = currentTheme === 'dark' ? '🌙' : '☀️';
    themeToggle.addEventListener('click', () => {
      const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
      const newTheme = isDark ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', newTheme);
      localStorage.setItem('fressi_theme', newTheme);
      themeToggle.textContent = newTheme === 'dark' ? '🌙' : '☀️';
    });
  }

  // Element References
  const photoCard = document.getElementById('photo-card');
  const validationCard = document.getElementById('validation-card');
  const photoInputCamera = document.getElementById('photo-input-camera');
  const photoInputGallery = document.getElementById('photo-input-gallery');
  const triggerCameraBtn = document.getElementById('trigger-camera-btn');
  const triggerGalleryBtn = document.getElementById('trigger-gallery-btn');
  const dropzone = document.getElementById('dropzone');
  const statusBox = document.getElementById('status-box');
  const toastContainer = document.getElementById('toast-container');

  // Form Fields
  const mealPhotoPreview = document.getElementById('meal-photo-preview');
  const mealTitle = document.getElementById('meal-title');
  const fieldDatetime = document.getElementById('field-datetime');
  const ingredientsChips = document.getElementById('ingredients-chips');
  const addIngredientInput = document.getElementById('add-ingredient-input');
  const btnAddIngredient = document.getElementById('btn-add-ingredient');
  const healthRatingDisplay = document.getElementById('health-rating-display');
  const aiModelInfo = document.getElementById('ai-model-info');
  const fieldPortion = document.getElementById('field-portion');
  const fieldCalories = document.getElementById('field-calories');
  const fieldNotes = document.getElementById('field-notes');
  const btnSave = document.getElementById('btn-save');
  const btnDiscard = document.getElementById('btn-discard');

  // Analysis State
  let currentAnalysis = {
    photoPath: '',
    title: '',
    ingredients: [],
    healthRating: '',
    baseCalories: 0,
    currentCalories: 0,
    portion: 100,
    notes: '',
    model: '',
    attempts: 0,
    durationMs: 0
  };

  let needsAiReanalysis = false;

  function markNeedsAiReanalysis() {
    needsAiReanalysis = true;
    if (btnSave && !btnSave.disabled) {
      btnSave.innerHTML = `Neu analysieren 🔄`;
    }
  }

  function resetAiReanalysisState() {
    needsAiReanalysis = false;
    if (btnSave && !btnSave.disabled) {
      btnSave.innerHTML = `Speichern 💾`;
    }
  }

  // Helper: Get formatted ISO string for datetime-local rounded to 15 mins
  function getFormatted15MinDateTime() {
    const now = new Date();
    const minutes = now.getMinutes();
    const roundedMinutes = Math.floor(minutes / 15) * 15;
    now.setMinutes(roundedMinutes, 0, 0);

    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const mins = String(now.getMinutes()).padStart(2, '0');

    return `${year}-${month}-${day}T${hours}:${mins}`;
  }

  // Camera & Photo Gallery Upload Handling
  if (triggerCameraBtn) {
    triggerCameraBtn.addEventListener('click', () => hideStatus());
  }
  if (triggerGalleryBtn) {
    triggerGalleryBtn.addEventListener('click', () => hideStatus());
  }

  function handleFileSelection(inputEl) {
    if (!inputEl) return;
    inputEl.addEventListener('change', async (e) => {
      const files = e.target.files;
      if (!files || files.length === 0) return;

      const file = files[0];

      if (!file.type.startsWith('image/')) {
        showStatus('Bitte wähle eine gültige Bilddatei (z. B. JPG, PNG, WEBP) aus.', 'error');
        showToast('Ungültiges Dateiformat! ⚠️');
        inputEl.value = '';
        return;
      }

      if (file.size > 10 * 1024 * 1024) {
        showStatus('Das ausgewählte Bild ist zu groß (max. 10 MB erlaubt).', 'error');
        showToast('Bild ist zu groß! ⚠️');
        inputEl.value = '';
        return;
      }

      await uploadPhoto(file);
    });
  }

  handleFileSelection(photoInputCamera);
  handleFileSelection(photoInputGallery);

  async function uploadPhoto(file) {
    showStatus('Foto wird hochgeladen & von KI analysiert... ⏳', 'info');
    if (triggerCameraBtn) {
      triggerCameraBtn.classList.add('disabled');
      triggerCameraBtn.innerHTML = `<span>Wird analysiert...</span> ⏳`;
    }
    if (triggerGalleryBtn) {
      triggerGalleryBtn.classList.add('disabled');
      triggerGalleryBtn.innerHTML = `<span>Wird analysiert...</span> ⏳`;
    }

    const formData = new FormData();
    formData.append('photo', file);
    formData.append('ajax_upload', '1');

    try {
      const response = await fetch('index.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
      });

      const contentType = response.headers.get('content-type') || '';
      let result;

      if (contentType.includes('application/json')) {
        result = await response.json();
      } else {
        const text = await response.text();
        console.warn('Server returned non-JSON response:', text);
        throw new Error(text.trim() || `Serverfehler (HTTP ${response.status})`);
      }

      if (response.ok && result.status === 'success' && result.data) {
        const data = result.data;
        showToast('Foto erfolgreich analysiert! 🎉');

        // Populate state
        currentAnalysis.photoPath = result.path || '';
        currentAnalysis.title = data.title || 'Mahlzeit';
        currentAnalysis.ingredients = Array.isArray(data.ingredients) ? data.ingredients : [];
        currentAnalysis.healthRating = data.health_rating || '';
        currentAnalysis.baseCalories = parseInt(data.calories, 10) || 0;
        currentAnalysis.currentCalories = currentAnalysis.baseCalories;
        currentAnalysis.portion = 100;
        currentAnalysis.notes = '';
        currentAnalysis.model = result.model || '';
        currentAnalysis.attempts = parseInt(result.attempts, 10) || 1;
        currentAnalysis.durationMs = parseInt(result.duration_ms, 10) || 0;

        renderValidationView();
      } else {
        const errMsg = result.message || 'Unbekannter Fehler.';
        showStatus(`<strong>❌ Upload abgelehnt:</strong> ${escapeHtml(errMsg)}`, 'error');
        showToast('Upload abgelehnt! ❌');
      }
    } catch (err) {
      console.error('Upload Error:', err);
      const displayMsg = err.message ? escapeHtml(err.message) : 'Verbindungsfehler.';
      showStatus(`<strong>❌ Fehler:</strong> ${displayMsg}`, 'error');
      showToast('Verbindungsfehler! ❌');
    } finally {
      if (photoInputCamera) photoInputCamera.value = '';
      if (photoInputGallery) photoInputGallery.value = '';
      if (triggerCameraBtn) {
        triggerCameraBtn.classList.remove('disabled');
        triggerCameraBtn.innerHTML = `Foto aufnehmen 📷`;
      }
      if (triggerGalleryBtn) {
        triggerGalleryBtn.classList.remove('disabled');
        triggerGalleryBtn.innerHTML = `Aus Galerie wählen 🖼️`;
      }
    }
  }

  // Render AI Model & Attempt Info Label
  function renderAiModelInfo() {
    if (!aiModelInfo) return;
    if (currentAnalysis.model) {
      const attempts = currentAnalysis.attempts || 1;
      const attemptStr = `${attempts}. Versuch`;
      aiModelInfo.textContent = `🤖 Analysiert mit ${currentAnalysis.model} (${attemptStr})`;
      aiModelInfo.style.display = 'flex';
    } else {
      aiModelInfo.style.display = 'none';
      aiModelInfo.textContent = '';
    }
  }

  // Render Validation Screen
  function renderValidationView() {
    if (mealPhotoPreview) mealPhotoPreview.src = currentAnalysis.photoPath;
    if (mealTitle) mealTitle.textContent = currentAnalysis.title;

    // Date & time preset (current time in 15min steps)
    if (fieldDatetime) fieldDatetime.value = getFormatted15MinDateTime();

    // Render health rating
    if (healthRatingDisplay) healthRatingDisplay.innerHTML = formatAiText(currentAnalysis.healthRating);

    // Render AI Model info label
    renderAiModelInfo();

    // Render portion & calories
    if (fieldPortion) fieldPortion.value = '100';
    if (fieldCalories) fieldCalories.value = currentAnalysis.currentCalories;
    if (fieldNotes) fieldNotes.value = '';

    // Render ingredients chips
    renderIngredientsChips();

    // Reset modification state
    resetAiReanalysisState();

    // Toggle card views & layout mode
    document.body.classList.remove('is-photo-mode');
    document.body.classList.add('is-validation-mode');
    if (photoCard) photoCard.style.display = 'none';
    if (validationCard) validationCard.style.display = 'block';
  }

  // Render Ingredients Chips
  function renderIngredientsChips() {
    if (!ingredientsChips) return;
    ingredientsChips.innerHTML = '';

    currentAnalysis.ingredients.forEach((ing, index) => {
      const chip = document.createElement('div');
      chip.className = 'ingredient-chip';

      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'ingredient-chip-input';
      input.value = ing;
      input.addEventListener('input', () => {
        markNeedsAiReanalysis();
      });
      input.addEventListener('change', (e) => {
        const newVal = e.target.value.trim();
        if (newVal) {
          currentAnalysis.ingredients[index] = newVal;
        } else {
          currentAnalysis.ingredients.splice(index, 1);
          renderIngredientsChips();
        }
        markNeedsAiReanalysis();
      });

      const removeBtn = document.createElement('span');
      removeBtn.className = 'chip-remove';
      removeBtn.innerHTML = '&times;';
      removeBtn.title = 'Zutat entfernen';
      removeBtn.addEventListener('click', () => {
        currentAnalysis.ingredients.splice(index, 1);
        renderIngredientsChips();
        markNeedsAiReanalysis();
      });

      chip.appendChild(input);
      chip.appendChild(removeBtn);
      ingredientsChips.appendChild(chip);
    });
  }

  // Add new ingredient
  function addIngredientFromInput() {
    if (!addIngredientInput) return;
    const val = addIngredientInput.value.trim();
    if (val) {
      currentAnalysis.ingredients.push(val);
      addIngredientInput.value = '';
      renderIngredientsChips();
      markNeedsAiReanalysis();
    }
  }

  if (btnAddIngredient) {
    btnAddIngredient.addEventListener('click', addIngredientFromInput);
  }
  if (addIngredientInput) {
    addIngredientInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        addIngredientFromInput();
      }
    });
  }

  // Notes changes trigger AI re-analysis
  if (fieldNotes) {
    fieldNotes.addEventListener('input', markNeedsAiReanalysis);
  }

  // Dynamic Portion % & Calorie Recalculation (does NOT trigger AI re-analysis)
  if (fieldPortion) {
    fieldPortion.addEventListener('change', () => {
      const portion = parseInt(fieldPortion.value, 10) || 100;
      currentAnalysis.portion = portion;

      if (currentAnalysis.baseCalories > 0) {
        const newCalories = Math.round(currentAnalysis.baseCalories * (portion / 100));
        currentAnalysis.currentCalories = newCalories;
        if (fieldCalories) fieldCalories.value = newCalories;
      }
    });
  }

  if (fieldCalories) {
    fieldCalories.addEventListener('change', () => {
      const val = parseInt(fieldCalories.value, 10) || 0;
      currentAnalysis.currentCalories = val;
    });
  }

  // Refinement Loop / Save Handler
  if (btnSave) {
    btnSave.addEventListener('click', async () => {
      // Read latest user values from input elements
      const latestIngredients = Array.from(document.querySelectorAll('.ingredient-chip-input'))
        .map(el => el.value.trim())
        .filter(val => val.length > 0);

      currentAnalysis.ingredients = latestIngredients;
      currentAnalysis.notes = fieldNotes ? fieldNotes.value.trim() : '';
      currentAnalysis.portion = parseInt(fieldPortion ? fieldPortion.value : 100, 10) || 100;
      currentAnalysis.currentCalories = parseInt(fieldCalories ? fieldCalories.value : 0, 10) || 0;

      // If no AI reanalysis is needed, save the meal to the PostgreSQL database
      if (!needsAiReanalysis) {
        btnSave.disabled = true;
        btnSave.innerHTML = `Speichere... ⏳`;

        const saveFormData = new FormData();
        saveFormData.append('action', 'save_meal');
        saveFormData.append('consumed_at', fieldDatetime ? fieldDatetime.value : '');
        saveFormData.append('title', currentAnalysis.title);
        saveFormData.append('photo_path', currentAnalysis.photoPath);
        saveFormData.append('ai_model', currentAnalysis.model);
        saveFormData.append('ai_attempts', currentAnalysis.attempts);
        saveFormData.append('processing_time_ms', currentAnalysis.durationMs);
        currentAnalysis.ingredients.forEach(ing => saveFormData.append('ingredients[]', ing));
        saveFormData.append('health_rating', currentAnalysis.healthRating);
        saveFormData.append('calories', currentAnalysis.currentCalories);

        try {
          const response = await fetch('index.php', {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: saveFormData
          });

          const saveResult = await response.json();

          if (response.ok && saveResult.status === 'success') {
            showToast('Mahlzeit erfolgreich gespeichert! 💾');
            historyLoadedInitial = false;

            // Reset state & form, return to photo capture screen
            currentAnalysis = {
              photoPath: '',
              title: '',
              ingredients: [],
              healthRating: '',
              baseCalories: 0,
              currentCalories: 0,
              portion: 100,
              notes: '',
              model: '',
              attempts: 0,
              durationMs: 0
            };

            resetAiReanalysisState();
            renderAiModelInfo();
            document.body.classList.remove('is-validation-mode');
            document.body.classList.add('is-photo-mode');
            if (validationCard) validationCard.style.display = 'none';
            if (photoCard) photoCard.style.display = 'block';
            hideStatus();
          } else {
            const errorMsg = saveResult.message || 'Unbekannter Fehler';
            const detail = saveResult.error_detail ? ` (${saveResult.error_detail})` : '';
            showToast(`Fehler beim Speichern: ${errorMsg}${detail} ⚠️`);
          }
        } catch (err) {
          console.error('Save error:', err);
          showToast('Verbindungsfehler beim Speichern! ❌');
        } finally {
          btnSave.disabled = false;
          btnSave.innerHTML = `Speichern 💾`;
        }
        return;
      }

      btnSave.disabled = true;
      btnSave.innerHTML = `Analysiere... ⏳`;

      const formData = new FormData();
      formData.append('action', 'refine_summary');
      formData.append('photo_path', currentAnalysis.photoPath);
      formData.append('previous_rating', currentAnalysis.healthRating);
      latestIngredients.forEach(ing => formData.append('ingredients[]', ing));
      formData.append('notes', currentAnalysis.notes);
      formData.append('portion', currentAnalysis.portion);
      formData.append('calories', currentAnalysis.currentCalories);

      try {
        const response = await fetch('index.php', {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: formData
        });

        const result = await response.json();

        if (response.ok && result.status === 'success' && result.data) {
          const data = result.data;

          // Update health rating with new refined description from AI
          if (data.health_rating) {
            currentAnalysis.healthRating = data.health_rating;
            if (healthRatingDisplay) {
              healthRatingDisplay.innerHTML = formatAiText(data.health_rating);
            }
          }

          if (data.title && mealTitle) {
            currentAnalysis.title = data.title;
            mealTitle.textContent = data.title;
          }

          if (Array.isArray(data.ingredients)) {
            currentAnalysis.ingredients = data.ingredients;
            renderIngredientsChips();
          }

          if (typeof data.calories !== 'undefined' && parseInt(data.calories, 10) > 0) {
            currentAnalysis.baseCalories = parseInt(data.calories, 10);
            currentAnalysis.currentCalories = currentAnalysis.baseCalories;
            if (fieldCalories) fieldCalories.value = currentAnalysis.currentCalories;
          }

          // Clear freitext notes field after successful AI re-evaluation
          if (fieldNotes) fieldNotes.value = '';
          currentAnalysis.notes = '';

          if (result.model) {
            currentAnalysis.model = result.model;
            currentAnalysis.attempts = parseInt(result.attempts, 10) || 1;
            currentAnalysis.durationMs = parseInt(result.duration_ms, 10) || 0;
            renderAiModelInfo();
          }

          resetAiReanalysisState();
          showToast('Wertigkeit von KI aktualisiert! 🎉');
        } else {
          showToast(`Fehler bei der Aktualisierung: ${result.message || 'Unbekannt'} ⚠️`);
        }
      } catch (err) {
        console.error('Refinement error:', err);
        showToast('Verbindungsfehler beim Refinement-Loop! ❌');
      } finally {
        btnSave.disabled = false;
        if (needsAiReanalysis) {
          btnSave.innerHTML = `Neu analysieren 🔄`;
        } else {
          btnSave.innerHTML = `Speichern 💾`;
        }
      }
    });
  }

  // Discard Button Click
  if (btnDiscard) {
    btnDiscard.addEventListener('click', () => {
      // Reset state and return to camera trigger screen
      currentAnalysis = {
        photoPath: '',
        title: '',
        ingredients: [],
        healthRating: '',
        baseCalories: 0,
        currentCalories: 0,
        portion: 100,
        notes: '',
        model: '',
        attempts: 0,
        durationMs: 0
      };

      resetAiReanalysisState();
      renderAiModelInfo();
      document.body.classList.remove('is-validation-mode');
      document.body.classList.add('is-photo-mode');
      if (validationCard) validationCard.style.display = 'none';
      if (photoCard) photoCard.style.display = 'block';
      hideStatus();
      showToast('Analyse verworfen. 🗑️');
    });
  }

  // ==========================================
  // Hamburger Menu & Dropdown Logic
  // ==========================================
  const hamburgerBtn = document.getElementById('hamburger-btn');
  const hamburgerDropdown = document.getElementById('hamburger-dropdown');
  const menuItemHistory = document.getElementById('menu-item-history');

  if (hamburgerBtn && hamburgerDropdown) {
    hamburgerBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isVisible = hamburgerDropdown.style.display === 'block';
      if (isVisible) {
        closeHamburgerDropdown();
      } else {
        openHamburgerDropdown();
      }
    });

    document.addEventListener('click', (e) => {
      if (!hamburgerDropdown.contains(e.target) && !hamburgerBtn.contains(e.target)) {
        closeHamburgerDropdown();
      }
    });
  }

  function openHamburgerDropdown() {
    if (!hamburgerDropdown || !hamburgerBtn) return;
    hamburgerDropdown.style.display = 'block';
    hamburgerBtn.classList.add('active');
    hamburgerBtn.setAttribute('aria-expanded', 'true');
  }

  function closeHamburgerDropdown() {
    if (!hamburgerDropdown || !hamburgerBtn) return;
    hamburgerDropdown.style.display = 'none';
    hamburgerBtn.classList.remove('active');
    hamburgerBtn.setAttribute('aria-expanded', 'false');
  }

  // ==========================================
  // View Switching & History Page Controller
  // ==========================================
  const historyCard = document.getElementById('history-card');
  const btnBackToCamera = document.getElementById('btn-back-to-camera');
  const historyListContainer = document.getElementById('history-list-container');
  const historyStatus = document.getElementById('history-status');
  const btnLoadMoreHistory = document.getElementById('btn-load-more-history');

  let historyPage = 0;
  let historyLoadedInitial = false;

  function showCameraView() {
    if (historyCard) historyCard.style.display = 'none';
    if (validationCard) validationCard.style.display = 'none';
    if (photoCard) photoCard.style.display = 'block';
    document.body.classList.remove('is-validation-mode');
    document.body.classList.add('is-photo-mode');
  }

  function showHistoryView() {
    if (photoCard) photoCard.style.display = 'none';
    if (validationCard) validationCard.style.display = 'none';
    if (historyCard) historyCard.style.display = 'block';

    document.body.classList.remove('is-photo-mode');
    document.body.classList.remove('is-validation-mode');

    if (!historyLoadedInitial) {
      historyPage = 0;
      if (historyListContainer) historyListContainer.innerHTML = '';
      loadHistoryPage(historyPage, false);
    }
  }

  if (menuItemHistory) {
    menuItemHistory.addEventListener('click', (e) => {
      e.preventDefault();
      closeHamburgerDropdown();
      showHistoryView();
    });
  }

  if (btnBackToCamera) {
    btnBackToCamera.addEventListener('click', () => {
      showCameraView();
    });
  }

  // Load history page from backend API
  async function loadHistoryPage(page, append = false) {
    if (!historyListContainer) return;

    if (!append) {
      historyListContainer.innerHTML = '';
      showHistoryStatus('Lade Mahlzeiten-Historie... ⏳', 'info');
    } else if (btnLoadMoreHistory) {
      btnLoadMoreHistory.disabled = true;
      btnLoadMoreHistory.innerHTML = 'Lade... ⏳';
    }

    try {
      const response = await fetch(`index.php?action=get_history&page=${page}`);
      const data = await response.json();

      hideHistoryStatus();

      if (data.status !== 'success') {
        showHistoryStatus(data.message || 'Fehler beim Laden der Historie.', 'error');
        return;
      }

      historyLoadedInitial = true;
      renderHistoryDays(data.days, append);

      if (btnLoadMoreHistory) {
        btnLoadMoreHistory.disabled = false;
        btnLoadMoreHistory.innerHTML = 'Mehr laden ⏳';
        btnLoadMoreHistory.style.display = data.has_more ? 'inline-block' : 'none';
      }
    } catch (err) {
      console.error('History fetch error:', err);
      hideHistoryStatus();
      showHistoryStatus('Netzwerkfehler beim Laden der Historie.', 'error');
      if (btnLoadMoreHistory) {
        btnLoadMoreHistory.disabled = false;
        btnLoadMoreHistory.innerHTML = 'Mehr laden ⏳';
      }
    }
  }

  function renderHistoryDays(days, append = false) {
    if (!historyListContainer || !days) return;

    let hasExistingBlocks = append && historyListContainer.children.length > 0;

    days.forEach((day, index) => {
      const dayBlock = document.createElement('div');
      dayBlock.className = 'history-day-block';

      // Insert horizontal line between days
      if (hasExistingBlocks || index > 0) {
        const divider = document.createElement('hr');
        divider.className = 'history-day-divider';
        historyListContainer.appendChild(divider);
      }

      const totalKcal = Number(day.total_calories || 0).toLocaleString('de-DE');
      const isEmpty = !day.meals || day.meals.length === 0;

      let html = `
        <div class="history-day-header">
          <div class="day-header-title">
            <span>📅 ${escapeHtml(day.weekday_name)}, ${escapeHtml(day.date_formatted)}</span>
          </div>
          <span class="day-total-badge ${isEmpty ? 'empty-badge' : ''}">${totalKcal} kcal</span>
        </div>
      `;

      if (isEmpty) {
        html += `<div class="history-empty-day-msg">Keine Einträge für diesen Tag</div>`;
      } else {
        day.meals.forEach((meal) => {
          const mealKcal = Number(meal.calories || 0).toLocaleString('de-DE');
          const jsonStr = escapeHtml(JSON.stringify(meal));
          html += `
            <div class="history-meal-item" data-meal='${jsonStr}'>
              <div class="meal-item-title">${escapeHtml(meal.title || 'Mahlzeit')}</div>
              <div class="meal-item-sub">
                <span>⏰ ${escapeHtml(meal.time_formatted)} Uhr</span>
                <span>•</span>
                <span>🔥 ${mealKcal} kcal</span>
              </div>
            </div>
          `;
        });
      }

      dayBlock.innerHTML = html;
      historyListContainer.appendChild(dayBlock);
    });
  }

  if (btnLoadMoreHistory) {
    btnLoadMoreHistory.addEventListener('click', () => {
      historyPage++;
      loadHistoryPage(historyPage, true);
    });
  }

  function showHistoryStatus(msg, type = 'info') {
    if (!historyStatus) return;
    historyStatus.className = `status-box status-${type}`;
    historyStatus.innerHTML = msg;
    historyStatus.style.display = 'block';
  }

  function hideHistoryStatus() {
    if (!historyStatus) return;
    historyStatus.style.display = 'none';
    historyStatus.innerHTML = '';
  }

  // Event Delegation for clicking on history meal items
  if (historyListContainer) {
    historyListContainer.addEventListener('click', (e) => {
      const mealItem = e.target.closest('.history-meal-item');
      if (mealItem && mealItem.dataset.meal) {
        try {
          const mealData = JSON.parse(mealItem.dataset.meal);
          openMealDetailModal(mealData);
        } catch (err) {
          console.error('Failed to parse meal json data', err);
        }
      }
    });
  }

  // ==========================================
  // Meal Detail Modal Controller
  // ==========================================
  const mealDetailModal = document.getElementById('meal-detail-modal');
  const btnCloseModal = document.getElementById('btn-close-modal');
  const modalMealTitle = document.getElementById('modal-meal-title');
  const modalMealDatetime = document.getElementById('modal-meal-datetime');
  const modalMealCalories = document.getElementById('modal-meal-calories');
  const modalMealHealth = document.getElementById('modal-meal-health');
  const modalIngredientsChips = document.getElementById('modal-ingredients-chips');
  const modalImageContainer = document.getElementById('modal-image-container');
  const modalMealImage = document.getElementById('modal-meal-image');

  function openMealDetailModal(meal) {
    if (!mealDetailModal) return;

    if (modalMealTitle) modalMealTitle.textContent = meal.title || 'Mahlzeit';
    if (modalMealDatetime) modalMealDatetime.textContent = meal.full_datetime_formatted || meal.consumed_at;
    if (modalMealCalories) modalMealCalories.textContent = `${Number(meal.calories || 0).toLocaleString('de-DE')} kcal`;
    if (modalMealHealth) modalMealHealth.innerHTML = formatAiText(meal.health_rating || 'Keine Angabe');

    if (modalIngredientsChips) {
      modalIngredientsChips.innerHTML = '';
      if (meal.ingredients) {
        const ingredientsList = typeof meal.ingredients === 'string' ? meal.ingredients.split(',') : meal.ingredients;
        ingredientsList.forEach((ing) => {
          const clean = ing.trim();
          if (clean) {
            const chip = document.createElement('span');
            chip.className = 'chip';
            chip.textContent = clean;
            modalIngredientsChips.appendChild(chip);
          }
        });
      }
    }

    if (meal.image_url && modalMealImage && modalImageContainer) {
      modalMealImage.src = meal.image_url;
      modalImageContainer.style.display = 'block';
    } else if (modalImageContainer) {
      modalImageContainer.style.display = 'none';
    }

    mealDetailModal.style.display = 'flex';
  }

  function closeMealDetailModal() {
    if (mealDetailModal) {
      mealDetailModal.style.display = 'none';
    }
  }

  if (btnCloseModal) {
    btnCloseModal.addEventListener('click', () => {
      closeMealDetailModal();
    });
  }

  if (mealDetailModal) {
    mealDetailModal.addEventListener('click', (e) => {
      if (e.target === mealDetailModal) {
        closeMealDetailModal();
      }
    });
  }

  // Helper formatting functions
  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, (m) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    }[m]));
  }

  function formatAiText(str) {
    if (!str) return '';
    let safe = escapeHtml(str);
    return safe.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
  }

  function showStatus(msg, type = 'info') {
    if (!statusBox) return;
    statusBox.className = `status-box status-${type}`;
    statusBox.innerHTML = msg;
    statusBox.style.display = 'block';
  }

  function hideStatus() {
    if (!statusBox) return;
    statusBox.style.display = 'none';
    statusBox.innerHTML = '';
  }

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
    }, 3500);
  }
});
