/**
 * FRESSI — Automatic Photo Capture, Structured Analysis & Refinement Loop Application
 */

document.addEventListener('DOMContentLoaded', () => {
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
  const photoInput = document.getElementById('photo-input');
  const triggerBtn = document.getElementById('trigger-btn');
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
    notes: ''
  };

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

  // Camera & Photo Upload Handling
  function openCamera() {
    hideStatus();
    if (photoInput) {
      photoInput.click();
    }
  }

  if (triggerBtn) {
    triggerBtn.addEventListener('click', openCamera);
  }
  if (dropzone) {
    dropzone.addEventListener('click', (e) => {
      if (!triggerBtn || (e.target !== triggerBtn && !triggerBtn.contains(e.target))) {
        openCamera();
      }
    });
  }

  if (photoInput) {
    photoInput.addEventListener('change', async (e) => {
      const files = e.target.files;
      if (!files || files.length === 0) return;

      const file = files[0];

      if (!file.type.startsWith('image/')) {
        showStatus('Bitte wähle eine gültige Bilddatei (z. B. JPG, PNG, WEBP) aus.', 'error');
        showToast('Ungültiges Dateiformat! ⚠️');
        photoInput.value = '';
        return;
      }

      if (file.size > 10 * 1024 * 1024) {
        showStatus('Das ausgewählte Bild ist zu groß (max. 10 MB erlaubt).', 'error');
        showToast('Bild ist zu groß! ⚠️');
        photoInput.value = '';
        return;
      }

      await uploadPhoto(file);
    });
  }

  async function uploadPhoto(file) {
    showStatus('Foto wird hochgeladen & von KI analysiert... ⏳', 'info');
    if (triggerBtn) {
      triggerBtn.disabled = true;
      triggerBtn.innerHTML = `<span>Wird analysiert...</span> ⏳`;
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
      if (photoInput) photoInput.value = '';
      if (triggerBtn) {
        triggerBtn.disabled = false;
        triggerBtn.innerHTML = `Foto aufnehmen 📷`;
      }
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

    // Render portion & calories
    if (fieldPortion) fieldPortion.value = '100';
    if (fieldCalories) fieldCalories.value = currentAnalysis.currentCalories;
    if (fieldNotes) fieldNotes.value = '';

    // Render ingredients chips
    renderIngredientsChips();

    // Toggle card views
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
      input.addEventListener('change', (e) => {
        const newVal = e.target.value.trim();
        if (newVal) {
          currentAnalysis.ingredients[index] = newVal;
        } else {
          currentAnalysis.ingredients.splice(index, 1);
          renderIngredientsChips();
        }
      });

      const removeBtn = document.createElement('span');
      removeBtn.className = 'chip-remove';
      removeBtn.innerHTML = '&times;';
      removeBtn.title = 'Zutat entfernen';
      removeBtn.addEventListener('click', () => {
        currentAnalysis.ingredients.splice(index, 1);
        renderIngredientsChips();
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

  // Dynamic Portion % & Calorie Recalculation
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

  // Refinement Loop (Speichern Button Click)
  if (btnSave) {
    btnSave.addEventListener('click', async () => {
      btnSave.disabled = true;
      btnSave.innerHTML = `Analysiere & Aktualisiere... ⏳`;

      // Read latest user values from input elements
      const latestIngredients = Array.from(document.querySelectorAll('.ingredient-chip-input'))
        .map(el => el.value.trim())
        .filter(val => val.length > 0);

      currentAnalysis.ingredients = latestIngredients;
      currentAnalysis.notes = fieldNotes ? fieldNotes.value.trim() : '';
      currentAnalysis.portion = parseInt(fieldPortion ? fieldPortion.value : 100, 10) || 100;
      currentAnalysis.currentCalories = parseInt(fieldCalories ? fieldCalories.value : 0, 10) || 0;

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

          showToast('Angaben gespeichert (Dummy) & Wertigkeit von KI aktualisiert! 🎉');
        } else {
          showToast(`Fehler bei der Aktualisierung: ${result.message || 'Unbekannt'} ⚠️`);
        }
      } catch (err) {
        console.error('Refinement error:', err);
        showToast('Verbindungsfehler beim Refinement-Loop! ❌');
      } finally {
        btnSave.disabled = false;
        btnSave.innerHTML = `Speichern 💾`;
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
        notes: ''
      };

      if (validationCard) validationCard.style.display = 'none';
      if (photoCard) photoCard.style.display = 'block';
      hideStatus();
      showToast('Analyse verworfen. 🗑️');
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
