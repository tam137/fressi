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

        if (text.includes('<!DOCTYPE html>') || text.includes('<html')) {
          if (text.includes('post_max_size') || response.status === 413) {
            throw new Error('Die Datei ist zu groß für den Upload auf dem Server.');
          }
          throw new Error('Server-Antwort ungültig (Sitzung eventuell abgelaufen). Bitte Seite neu laden.');
        }

        throw new Error(text.trim() || `Serverfehler (HTTP ${response.status})`);
      }

      if (response.ok && result.status === 'success') {
        let statusHtml = `<strong>✅ ${result.message}</strong>`;
        if (result.ai_description) {
          statusHtml += `<div class="ai-description">🤖 <strong>KI-Ernährungsratgeber:</strong><br><br>${formatAiDescription(result.ai_description)}</div>`;
        }
        showStatus(statusHtml, 'success');
        showToast('Foto erfolgreich analysiert! 🎉');
      } else {
        const errMsg = result.message || 'Unbekannter Fehler.';
        showStatus(`<strong>❌ Upload abgelehnt:</strong> ${escapeHtml(errMsg)}`, 'error');
        showToast('Upload abgelehnt! ❌');
      }
    } catch (err) {
      console.error('Upload Error:', err);
      const isNetworkErr = err.name === 'TypeError' || err.message.includes('fetch');
      const displayMsg = !isNetworkErr && err.message
        ? escapeHtml(err.message)
        : 'Netzwerk- oder Serververbindung fehlgeschlagen.';
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

  function formatAiDescription(str) {
    if (!str) return '';
    let safe = escapeHtml(str);
    return safe.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
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
