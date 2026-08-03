/**
 * FRESSI — Photo Capture & Upload Application
 */

document.addEventListener('DOMContentLoaded', () => {
  // Theme Management
  const themeToggle = document.getElementById('theme-toggle');
  const currentTheme = localStorage.getItem('fressi_theme') || 'dark';
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

  // Camera & Photo Upload Handling
  const photoInput = document.getElementById('photo-input');
  const triggerBtn = document.getElementById('trigger-btn');
  const dropzone = document.getElementById('dropzone');
  const previewContainer = document.getElementById('preview-container');
  const imagePreview = document.getElementById('image-preview');
  const fileNameDisplay = document.getElementById('file-name-display');
  const fileSizeDisplay = document.getElementById('file-size-display');
  const uploadBtn = document.getElementById('upload-btn');
  const resetBtn = document.getElementById('reset-btn');
  const statusBox = document.getElementById('status-box');
  const toastContainer = document.getElementById('toast-container');

  let selectedFile = null;
  let previewUrl = null;

  // Open native camera / file picker
  if (triggerBtn && photoInput) {
    triggerBtn.addEventListener('click', () => photoInput.click());
  }
  if (dropzone && photoInput) {
    dropzone.addEventListener('click', (e) => {
      if (e.target !== triggerBtn) {
        photoInput.click();
      }
    });
  }

  // File selection event
  if (photoInput) {
    photoInput.addEventListener('change', (e) => {
      const files = e.target.files;
      if (!files || files.length === 0) return;
      handleFileSelected(files[0]);
    });
  }

  function handleFileSelected(file) {
    // Validate file type on client side
    if (!file.type.startsWith('image/')) {
      showStatus('Bitte wähle eine gültige Bilddatei (z.B. JPG, PNG, WEBP) aus.', 'error');
      showToast('Ungültiges Dateiformat! ⚠️');
      return;
    }

    // Validate size (10 MB limit)
    if (file.size > 10 * 1024 * 1024) {
      showStatus('Das ausgewählte Bild ist zu groß (max. 10 MB erlaubt).', 'error');
      showToast('Bild ist zu groß! ⚠️');
      return;
    }

    selectedFile = file;

    // Create preview
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
    }
    previewUrl = URL.createObjectURL(file);
    imagePreview.src = previewUrl;

    fileNameDisplay.textContent = file.name;
    fileSizeDisplay.textContent = formatBytes(file.size);

    // Toggle UI views
    dropzone.style.display = 'none';
    previewContainer.style.display = 'block';
    hideStatus();
  }

  // Reset photo selection
  if (resetBtn) {
    resetBtn.addEventListener('click', resetPhotoSelection);
  }

  function resetPhotoSelection() {
    selectedFile = null;
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = null;
    }
    if (photoInput) photoInput.value = '';
    imagePreview.src = '';
    previewContainer.style.display = 'none';
    dropzone.style.display = 'flex';
    hideStatus();
  }

  // Upload to Server via AJAX
  if (uploadBtn) {
    uploadBtn.addEventListener('click', async () => {
      if (!selectedFile) {
        showStatus('Bitte nimm zuerst ein Foto auf.', 'error');
        return;
      }

      uploadBtn.disabled = true;
      uploadBtn.innerHTML = `<span>Wird hochgeladen...</span> ⏳`;
      showStatus('Das Foto wird auf den Server übertragen...', 'info');

      const formData = new FormData();
      formData.append('photo', selectedFile);
      formData.append('ajax_upload', '1');

      try {
        const response = await fetch('index.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();

        if (result.status === 'success') {
          showStatus(`<strong>Erfolg!</strong> ${result.message}<br><small style="opacity: 0.85; margin-top: 4px; display: inline-block;">Gespeichert als: <code>${result.filename}</code></small>`, 'success');
          showToast('Foto erfolgreich gespeichert! 🎉');
          
          uploadBtn.disabled = false;
          uploadBtn.innerHTML = `<span>Erneut speichern</span> 🚀`;
        } else {
          showStatus(result.message || 'Fehler beim Upload.', 'error');
          showToast('Fehler beim Speichern! ❌');
          uploadBtn.disabled = false;
          uploadBtn.innerHTML = `<span>Auf Server speichern</span> 🚀`;
        }
      } catch (err) {
        console.error('Upload Error:', err);
        showStatus('Netzwerk- oder Serverfehler beim Upload.', 'error');
        showToast('Verbindungsfehler! ❌');
        uploadBtn.disabled = false;
        uploadBtn.innerHTML = `<span>Auf Server speichern</span> 🚀`;
      }
    });
  }

  // Helper Functions
  function formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
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
    }, 3000);
  }
});
