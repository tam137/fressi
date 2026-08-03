/**
 * FRESSI — Automatic Photo Capture & Upload Application
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

  // Camera & Automatic Photo Upload Handling
  const photoInput = document.getElementById('photo-input');
  const triggerBtn = document.getElementById('trigger-btn');
  const dropzone = document.getElementById('dropzone');
  const statusBox = document.getElementById('status-box');
  const toastContainer = document.getElementById('toast-container');

  // Trigger camera dialog
  function openCamera() {
    hideStatus(); // Clear previous status when starting a new capture
    if (photoInput) {
      photoInput.click();
    }
  }

  if (triggerBtn) {
    triggerBtn.addEventListener('click', openCamera);
  }
  if (dropzone) {
    dropzone.addEventListener('click', (e) => {
      if (e.target !== triggerBtn) {
        openCamera();
      }
    });
  }

  // File selection event -> AUTOMATIC UPLOAD IMMEDIATELY
  if (photoInput) {
    photoInput.addEventListener('change', async (e) => {
      const files = e.target.files;
      if (!files || files.length === 0) return;

      const file = files[0];

      // Client-side validation
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

      // Start automatic upload
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
        body: formData
      });

      const result = await response.json();

      if (result.status === 'success') {
        let statusHtml = `<strong>✅ ${result.message}</strong><br><small style="opacity: 0.85; margin-top: 4px; display: inline-block;">Datei: <code>${result.filename}</code> (${result.uploaded_at})</small>`;
        if (result.ai_description) {
          statusHtml += `<div class="ai-description" style="margin-top: 10px; padding-top: 8px; border-top: 1px solid rgba(255, 255, 255, 0.15); text-align: left;">🤖 <strong>KI-Erkenntnis:</strong> ${escapeHtml(result.ai_description)}</div>`;
        }
        showStatus(statusHtml, 'success');
        showToast('Foto erfolgreich gespeichert & analysiert! 🎉');
      } else {
        showStatus(`<strong>❌ Upload abgelehnt:</strong> ${escapeHtml(result.message || 'Unbekannter Fehler.')}`, 'error');
        showToast('Upload abgelehnt! ❌');
      }
    } catch (err) {
      console.error('Upload Error:', err);
      showStatus('<strong>❌ Fehler:</strong> Netzwerk- oder Serververbindung fehlgeschlagen.', 'error');
      showToast('Verbindungsfehler! ❌');
    } finally {
      if (photoInput) photoInput.value = '';
      if (triggerBtn) {
        triggerBtn.disabled = false;
        triggerBtn.innerHTML = `Foto aufnehmen 📷`;
      }
    }
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, function(m) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[m];
    });
  }

  // Helper Functions
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
