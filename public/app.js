// Resolve API beside this app.js file: works both at the domain root and under /public/.
const API_BASE = new URL('./api', document.currentScript.src).pathname.replace(/\/$/, '');
const $ = (selector) => document.querySelector(selector);

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('./service-worker.js').catch(() => {});
  }, { once: true });
}

let pendingInstallPrompt = null;
const installButton = $('#install-app');
const isInstalledApp = window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;
installButton.hidden = Boolean(isInstalledApp);
window.addEventListener('beforeinstallprompt', (event) => {
  event.preventDefault();
  pendingInstallPrompt = event;
});
window.addEventListener('appinstalled', () => {
  pendingInstallPrompt = null;
  installButton.hidden = true;
  showAppStatus('PlusChat установлен на устройство.', true);
});
installButton.addEventListener('click', async () => {
  if (!pendingInstallPrompt) {
    showAppStatus('Откройте меню браузера и выберите «Установить приложение» или «Добавить на главный экран».');
    return;
  }
  installButton.disabled = true;
  try {
    const promptEvent = pendingInstallPrompt;
    pendingInstallPrompt = null;
    await promptEvent.prompt();
    const choice = await promptEvent.userChoice;
    if (choice?.outcome === 'accepted') showAppStatus('Установка PlusChat запущена.', true);
  } catch {
    showAppStatus('Не удалось открыть установку. Попробуйте через меню браузера.');
  } finally {
    installButton.disabled = false;
  }
});

const loginView = $('#login-view');
const registerView = $('#register-view');
const successView = $('#success-view');
const chatView = $('#chat-view');
const registrationEmail = $('#register-email');
const usernameInput = $('#register-username');
const avatarInput = $('#avatar-file');
let usernameAvailable = false;
let usernameTimer;
let usernameCheckSequence = 0;
let avatarObjectUrl;
let resendCodeTimer = null;
let passwordResetCodeTimer = null;

function showMessage(element, text, success = false) {
  element.textContent = text;
  element.classList.toggle('success', success);
}

function showAppStatus(text, success = false) {
  const status = $('#app-status');
  showMessage(status, text, success);
  status.hidden = !text;
}

function rateLimitMessage(data) {
  const seconds = Math.max(1, Number(data?.retryAfter) || 60);
  return `Слишком много попыток. Попробуйте снова через ${seconds >= 60 ? `${Math.ceil(seconds / 60)} мин.` : `${seconds} сек.`}`;
}

function ageGroupLabel(group) {
  return ({ under_16: 'до 16 лет', '16_17': '16–17 лет', adult: '18+', unknown: 'возраст не указан' })[group] || 'возраст не указан';
}

function startRegistrationCodeCountdown(seconds = 60) {
  const button = $('#resend-registration-code');
  clearInterval(resendCodeTimer);
  let remaining = Math.max(0, Math.ceil(seconds));
  button.disabled = remaining > 0;
  const update = () => {
    button.textContent = remaining > 0 ? `Отправить код ещё раз через ${remaining} с` : 'Отправить код ещё раз';
    if (remaining <= 0) {
      button.disabled = false;
      clearInterval(resendCodeTimer);
      resendCodeTimer = null;
      return;
    }
    remaining -= 1;
  };
  update();
  if (remaining > 0) resendCodeTimer = setInterval(update, 1000);
}

function startPasswordResetCodeCountdown(seconds = 60) {
  const button = $('#reset-send-code');
  clearInterval(passwordResetCodeTimer);
  let remaining = Math.max(0, Math.ceil(seconds));
  button.disabled = remaining > 0;
  const update = () => {
    button.textContent = remaining > 0 ? `Повторить через ${remaining} с` : 'Получить код ещё раз';
    if (remaining <= 0) {
      button.disabled = false;
      clearInterval(passwordResetCodeTimer);
      passwordResetCodeTimer = null;
      return;
    }
    remaining -= 1;
  };
  update();
  if (remaining > 0) passwordResetCodeTimer = setInterval(update, 1000);
}

async function api(path, options = {}) {
  const response = await fetch(`${API_BASE}${path}`, {
    credentials: 'same-origin',
    ...options,
    headers: { Accept: 'application/json', ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), ...options.headers },
  });
  const data = await response.json().catch(() => ({}));
  if (response.status === 401 && path !== '/auth/login.php' && currentUser) handleSessionExpired();
  return { response, data };
}

function handleSessionExpired() {
  if (!currentUser) return;
  messageRequestController?.abort();
  currentUser = null;
  currentConversationId = null;
  currentConversationKind = null;
  lastMessageId = 0;
  oldestMessageId = 0;
  clearInterval(pollTimer);
  clearInterval(chatRefreshTimer);
  document.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close());
  chatView.classList.remove('thread-open');
  showLogin();
  showMessage($('#login-message'), 'Сеанс завершён или доступ к устройству отозван. Войдите снова.');
}

function showLogin() {
  loginView.hidden = false;
  registerView.hidden = true;
  successView.hidden = true;
  chatView.hidden = true;
}

function showRegistration() {
  loginView.hidden = true;
  registerView.hidden = false;
  successView.hidden = true;
  chatView.hidden = true;
  setStep('email');
}

function setStep(step) {
  const names = ['email', 'code', 'profile', 'password'];
  const index = names.indexOf(step);
  names.forEach((name) => { $(`#${name}-step`).hidden = name !== step; });
  $('#register-eyebrow').textContent = `Новый аккаунт · шаг ${index + 1} из 4`;
  $('#step-track-fill').style.width = `${((index + 1) / names.length) * 100}%`;
  const labels = {
    email: ['Ваша почта', 'Отправим код, чтобы подтвердить адрес.'],
    code: ['Проверьте почту', `Введите шестизначный код, отправленный на ${registrationEmail.value}.`],
    profile: ['Настройте профиль', 'Добавьте имя и выберите свободный @логин.'],
    password: ['Защитите аккаунт', 'Придумайте пароль. Его можно сохранить в менеджере паролей браузера.'],
  };
  $('#register-title').textContent = labels[step][0];
  $('#register-subtitle').textContent = labels[step][1];
}

$('#show-register').addEventListener('click', showRegistration);
$('#show-login').addEventListener('click', showLogin);

$('#toggle-login-password').addEventListener('click', (event) => {
  const input = $('#login-password');
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  event.currentTarget.textContent = show ? 'Скрыть' : 'Показать';
  event.currentTarget.setAttribute('aria-pressed', String(show));
});

$('#toggle-new-password').addEventListener('click', (event) => {
  const input = $('#new-password');
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  event.currentTarget.textContent = show ? 'Скрыть' : 'Показать';
  event.currentTarget.setAttribute('aria-pressed', String(show));
});

$('#login-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const message = $('#login-message');
  showMessage(message, '');
  if (!event.currentTarget.reportValidity()) return;
  const button = $('#login-submit');
  button.disabled = true;
  button.querySelector('span').textContent = 'Входим…';
  try {
    const { response, data } = await api('/auth/login.php', {
      method: 'POST',
      body: JSON.stringify({ email: $('#login-email').value.trim(), password: $('#login-password').value }),
    });
    if (!response.ok) {
      const errorMessage = response.status === 429
        ? rateLimitMessage(data)
        : data.error === 'invalid_email'
          ? 'Введите корректный адрес электронной почты.'
          : response.status === 401 || response.status === 422
            ? 'Проверьте почту и пароль.'
            : 'Не удалось войти. Попробуйте позже.';
      showMessage(message, errorMessage);
      return;
    }
    openMessenger(data.user);
  } catch {
    showMessage(message, 'Не удалось связаться с сервером. Проверьте подключение.');
  } finally {
    button.disabled = false;
    button.querySelector('span').textContent = 'Войти';
  }
});

$('#email-step').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const button = form.querySelector('.submit-button');
  const message = $('#email-message');
  showMessage(message, '');
  button.disabled = true;
  button.querySelector('span').textContent = 'Отправляем…';
  try {
    const { response, data } = await api('/auth/request-code.php', {
      method: 'POST',
      body: JSON.stringify({ email: registrationEmail.value.trim() }),
    });
    if (!response.ok) {
      const errors = {
        invalid_email: 'Введите корректный адрес электронной почты.',
        email_already_registered: 'Аккаунт с этой почтой уже есть. Попробуйте войти.',
        please_wait_before_resending: 'Код уже отправлен. Подождите минуту перед повторным запросом.',
        too_many_attempts: rateLimitMessage(data),
        mail_unavailable: 'Не удалось отправить письмо. Попробуйте позже.',
      };
      showMessage(message, errors[data.error] || 'Не удалось отправить код. Попробуйте позже.');
      return;
    }
    setStep('code');
    $('#verification-code').value = '';
    startRegistrationCodeCountdown();
    $('#verification-code').focus();
  } catch {
    showMessage(message, 'Не удалось связаться с сервером.');
  } finally {
    button.disabled = false;
    button.querySelector('span').textContent = 'Получить код';
  }
});

$('#resend-registration-code').addEventListener('click', async (event) => {
  const button = event.currentTarget;
  if (button.disabled) return;
  button.disabled = true;
  button.textContent = 'Отправляем…';
  const message = $('#code-message');
  showMessage(message, '');
  try {
    const { response, data } = await api('/auth/request-code.php', {
      method: 'POST',
      body: JSON.stringify({ email: registrationEmail.value.trim() }),
    });
    if (!response.ok) {
      const errors = {
        invalid_email: 'Введите корректный адрес электронной почты.',
        email_already_registered: 'Аккаунт с этой почтой уже есть. Попробуйте войти.',
        please_wait_before_resending: 'Подождите немного перед повторной отправкой.',
        too_many_attempts: rateLimitMessage(data),
        mail_unavailable: 'Не удалось отправить письмо. Попробуйте позже.',
      };
      showMessage(message, errors[data.error] || 'Не удалось отправить код. Попробуйте позже.');
      startRegistrationCodeCountdown(data.error === 'too_many_attempts' ? Math.max(60, Number(data.retryAfter) || 60) : 5);
      return;
    }
    $('#verification-code').value = '';
    showMessage(message, 'Новый код отправлен на почту.', true);
    startRegistrationCodeCountdown();
  } catch {
    showMessage(message, 'Не удалось связаться с сервером.');
    button.disabled = false;
    button.textContent = 'Отправить код ещё раз';
  }
});

$('#code-step').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!event.currentTarget.reportValidity()) return;
  const message = $('#code-message');
  showMessage(message, '');
  const button = event.currentTarget.querySelector('.submit-button');
  button.disabled = true;
  button.querySelector('span').textContent = 'Проверяем…';
  try {
    const { response, data } = await api('/auth/verify-code.php', {
      method: 'POST',
      body: JSON.stringify({ email: registrationEmail.value.trim(), code: $('#verification-code').value.trim() }),
    });
    if (!response.ok) {
      showMessage(message, data.error === 'invalid_code' ? 'Неверный код. Проверьте письмо и попробуйте ещё раз.' : 'Код истёк или неверен. Запросите новый код.');
      return;
    }
    setStep('profile');
    usernameInput.focus();
  } catch {
    showMessage(message, 'Не удалось связаться с сервером.');
  } finally {
    button.disabled = false;
    button.querySelector('span').textContent = 'Подтвердить почту';
  }
});

async function checkUsername() {
  const value = usernameInput.value.trim().toLowerCase();
  const checkSequence = ++usernameCheckSequence;
  usernameInput.value = value;
  const hint = $('#username-message');
  usernameAvailable = false;
  if (!/^[a-z0-9_]{3,24}$/.test(value)) {
    hint.textContent = 'Нужно 3–24 символа: латинские буквы, цифры или _';
    hint.classList.remove('available');
    return false;
  }
  hint.textContent = 'Проверяем…';
  hint.classList.remove('available');
  try {
    const { response, data } = await api(`/users/username.php?username=${encodeURIComponent(value)}`, { headers: {} });
    if (!response.ok) throw new Error('check failed');
    if (checkSequence !== usernameCheckSequence || usernameInput.value.trim().toLowerCase() !== value) return false;
    usernameAvailable = Boolean(data.available);
    hint.textContent = usernameAvailable ? `@${value} свободно` : 'Это имя уже занято. Попробуйте другое.';
    hint.classList.toggle('available', usernameAvailable);
    return usernameAvailable;
  } catch {
    if (checkSequence !== usernameCheckSequence || usernameInput.value.trim().toLowerCase() !== value) return false;
    hint.textContent = 'Не удалось проверить имя. Повторите попытку.';
    return false;
  }
}

usernameInput.addEventListener('input', () => {
  clearTimeout(usernameTimer);
  usernameCheckSequence += 1;
  usernameAvailable = false;
  usernameTimer = setTimeout(checkUsername, 350);
});
usernameInput.addEventListener('blur', checkUsername);

avatarInput.addEventListener('change', () => {
  const file = avatarInput.files?.[0];
  if (!file) return;
  if (file.size > 4 * 1024 * 1024) {
    avatarInput.value = '';
    $('#profile-message').textContent = 'Аватар должен быть не больше 4 МБ.';
    return;
  }
  if (avatarObjectUrl) URL.revokeObjectURL(avatarObjectUrl);
  avatarObjectUrl = URL.createObjectURL(file);
  $('#avatar-preview').src = avatarObjectUrl;
  $('#avatar-preview').hidden = false;
  $('#avatar-placeholder').hidden = true;
});

async function continueFromProfile() {
  if (!$('#profile-step').reportValidity()) return;
  if (!usernameAvailable && !(await checkUsername())) return;
  if (!$('#display-name').value.trim()) {
    $('#display-name').focus();
    return;
  }
  setStep('password');
  $('#new-password').focus();
}

$('#profile-step').addEventListener('submit', async (event) => {
  event.preventDefault();
  const message = $('#profile-message');
  showMessage(message, '');
  await continueFromProfile();
});

$('#skip-bio').addEventListener('click', async () => {
  $('#register-bio').value = '';
  await continueFromProfile();
});

document.querySelectorAll('[data-back="email"]').forEach((button) => button.addEventListener('click', () => setStep('email')));
document.querySelectorAll('[data-back="profile"]').forEach((button) => button.addEventListener('click', () => setStep('profile')));

function passwordRules(value) {
  return {
    length: value.length >= 8 && new TextEncoder().encode(value).length <= 72,
    lower: /[a-z]/.test(value),
    upper: /[A-Z]/.test(value),
    digit: /\d/.test(value),
    symbol: /[^\p{L}\p{N}]/u.test(value),
  };
}

$('#new-password').addEventListener('input', () => {
  const rules = passwordRules($('#new-password').value);
  Object.entries(rules).forEach(([rule, passed]) => $(`[data-rule="${rule}"]`).classList.toggle('passed', passed));
});

$('#generate-password').addEventListener('click', () => {
  const groups = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#$%*-_'];
  const randomIndex = (length) => {
    const range = 0x100000000;
    const ceiling = range - (range % length);
    const value = new Uint32Array(1);
    do { crypto.getRandomValues(value); } while (value[0] >= ceiling);
    return value[0] % length;
  };
  const pick = (group) => group[randomIndex(group.length)];
  const chars = groups.map(pick);
  const all = groups.join('');
  while (chars.length < 14) chars.push(pick(all));
  for (let i = chars.length - 1; i > 0; i -= 1) {
    const j = randomIndex(i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }
  $('#new-password').value = chars.join('');
  $('#confirm-password').value = chars.join('');
  $('#new-password').type = 'text';
  $('#toggle-new-password').textContent = 'Скрыть';
  $('#toggle-new-password').setAttribute('aria-pressed', 'true');
  $('#new-password').dispatchEvent(new Event('input'));
});

$('#password-step').addEventListener('submit', async (event) => {
  event.preventDefault();
  const message = $('#password-message');
  showMessage(message, '');
  const password = $('#new-password').value;
  if (!event.currentTarget.reportValidity()) return;
  if (!Object.values(passwordRules(password)).every(Boolean)) {
    showMessage(message, 'Пароль должен соответствовать всем требованиям.');
    return;
  }
  if (password !== $('#confirm-password').value) {
    showMessage(message, 'Пароли не совпадают.');
    return;
  }

  const button = $('#register-submit');
  button.disabled = true;
  button.querySelector('span').textContent = 'Создаём аккаунт…';
  const payload = new FormData();
  payload.append('email', registrationEmail.value.trim());
  payload.append('username', usernameInput.value.trim().toLowerCase());
  payload.append('displayName', $('#display-name').value.trim());
  payload.append('bio', $('#register-bio').value.trim());
  payload.append('password', password);
  if (avatarInput.files?.[0]) payload.append('avatar', avatarInput.files[0]);
  try {
    const { response, data } = await api('/auth/register.php', { method: 'POST', body: payload });
    if (!response.ok) {
      const errors = {
        email_or_username_taken: 'Почта или имя пользователя уже заняты.',
        invalid_username: 'Проверьте имя пользователя.',
        password_policy: 'Пароль не соответствует требованиям.',
        account_created_login_again: 'Аккаунт создан. Войдите с этой почтой и паролем.',
      };
      if (data.error === 'email_not_verified') {
        setStep('email');
        showMessage($('#email-message'), 'Срок подтверждения истёк. Запросите новый код, чтобы продолжить регистрацию.');
        return;
      }
      showMessage(message, errors[data.error] || 'Не удалось создать аккаунт. Попробуйте ещё раз.');
      if (data.error === 'email_or_username_taken') setStep('profile');
      if (data.error === 'account_created_login_again') {
        showLogin();
        $('#login-email').value = registrationEmail.value.trim();
        showMessage($('#login-message'), errors[data.error]);
      }
      return;
    }
    openMessenger(data.user);
  } catch {
    showMessage(message, 'Не удалось связаться с сервером.');
  } finally {
    button.disabled = false;
    button.querySelector('span').textContent = 'Создать аккаунт';
  }
});

let currentUser = null;
let currentConversationId = null;
let currentConversationKind = null;
let conversationViewToken = 0;
let lastMessageId = 0;
let oldestMessageId = 0;
let messageRequestInFlight = false;
let olderMessagesInFlight = false;
let messageRequestController = null;
let pollTimer = null;
let chatRefreshTimer = null;
let selectedPackId = null;
let availablePacks = [];
let giphyKey = null;
let gifSearchTimer;
let gifSearchSequence = 0;

function resumeChatRefresh() {
  clearInterval(chatRefreshTimer);
  if (!document.hidden && currentUser && !chatView.hidden) {
    chatRefreshTimer = setInterval(loadChats, 8000);
  }
}

function resumeMessagePolling() {
  clearInterval(pollTimer);
  if (!document.hidden && currentConversationId) {
    pollTimer = setInterval(() => loadMessages(false), 1800);
  }
}

document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    clearInterval(chatRefreshTimer);
    clearInterval(pollTimer);
    return;
  }
  if (currentUser && !chatView.hidden) loadChats();
  if (currentConversationId) loadMessages(false);
  resumeChatRefresh();
  resumeMessagePolling();
});

function openMessenger(user) {
  currentUser = user || currentUser;
  loginView.hidden = true;
  registerView.hidden = true;
  successView.hidden = true;
  chatView.hidden = false;
  $('#account-label').textContent = currentUser?.username ? `@${currentUser.username}` : (currentUser?.email || 'Аккаунт');
  $('#thread-empty').hidden = false;
  $('#active-thread').hidden = true;
  chatView.classList.remove('thread-open');
  loadChats();
  resumeChatRefresh();
  loadGiphyKey();
}

async function restoreSession() {
  try {
    const { response, data } = await api('/auth/me.php', { headers: {} });
    if (response.ok) openMessenger(data.user);
  } catch { /* The login screen remains available while offline. */ }
}

async function loadChats() {
  if (chatView.hidden || document.hidden) return;
  try {
    const { response, data } = await api('/chats/index.php', { headers: {} });
    if (!response.ok) {
      const list = $('#chat-list');
      if (!list.children.length && response.status !== 401) {
        $('#chat-list-empty').textContent = 'Не удалось загрузить чаты. Проверьте соединение.';
        $('#chat-list-empty').hidden = false;
      }
      return;
    }
    const list = $('#chat-list');
    const previousScrollTop = list.scrollTop;
    list.replaceChildren();
    $('#chat-list-empty').textContent = 'Здесь появятся ваши переписки.';
    $('#chat-list-empty').hidden = (data.chats || []).length > 0;
    for (const chat of data.chats || []) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `chat-item${Number(chat.id) === currentConversationId ? ' active' : ''}`;
      const avatar = document.createElement('span');
      avatar.className = 'chat-item-avatar';
      const avatarInitial = (chat.title || chat.peer_name || 'Ч').slice(0, 1).toUpperCase();
      avatar.textContent = avatarInitial;
      if (chat.peer_avatar_url) {
        const image = document.createElement('img');
        image.className = 'chat-avatar-image';
        image.alt = '';
        image.src = chat.peer_avatar_url;
        image.addEventListener('error', () => { avatar.replaceChildren(); avatar.textContent = avatarInitial; }, { once: true });
        avatar.replaceChildren(image);
      }
      const copy = document.createElement('span');
      copy.className = 'chat-item-copy';
      const title = document.createElement('strong');
      title.textContent = chat.title || chat.peer_name || (chat.kind === 'local' ? 'Местный чат' : 'Переписка');
      const preview = document.createElement('span');
      preview.textContent = chat.last_message || (chat.last_message_type === 'sticker' ? 'Стикер' : chat.last_message_type === 'gif' ? 'GIF' : 'Пока нет сообщений');
      copy.append(title, preview);
      button.append(avatar, copy);
      const unreadCount = Number(chat.unread_count) || 0;
      if (unreadCount > 0) {
        const unread = document.createElement('span');
        unread.className = 'chat-unread-badge';
        unread.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
        unread.setAttribute('aria-label', `Непрочитанных сообщений: ${unreadCount}`);
        button.append(unread);
      }
      button.addEventListener('click', () => openConversation(Number(chat.id), chat));
      list.append(button);
    }
    list.scrollTop = previousScrollTop;
  } catch {
    const list = $('#chat-list');
    if (!list.children.length) {
      $('#chat-list-empty').textContent = 'Не удалось загрузить чаты. Проверьте соединение.';
      $('#chat-list-empty').hidden = false;
    }
  }
}

async function openConversation(id, chat = {}) {
  messageRequestController?.abort();
  conversationViewToken += 1;
  currentConversationId = id;
  currentConversationKind = chat.kind || null;
  lastMessageId = 0;
  oldestMessageId = 0;
  olderMessagesInFlight = false;
  $('#message-input').disabled = false;
  $('#message-form .send-button').disabled = false;
  $('#messages').replaceChildren();
  const emptyState = document.createElement('p');
  emptyState.id = 'messages-empty';
  emptyState.className = 'messages-empty';
  emptyState.setAttribute('role', 'status');
  emptyState.textContent = 'Загружаем историю…';
  $('#messages').append(emptyState);
  $('#message-status').hidden = true;
  $('#message-status').textContent = '';
  $('#thread-empty').hidden = true;
  $('#active-thread').hidden = false;
  chatView.classList.add('thread-open');
  $('#thread-title').textContent = chat.title || chat.peer_name || 'Переписка';
  $('#thread-subtitle').textContent = chat.subtitle || (chat.peer_username ? `@${chat.peer_username}` : (chat.kind === 'group' ? 'Групповой чат' : chat.kind === 'local' ? `Возрастная группа: ${ageGroupLabel(chat.age_group)}` : 'Сообщения PlusChat'));
  const threadAvatar = $('#thread-avatar');
  const threadInitial = (chat.title || chat.peer_name || 'Ч').slice(0, 1).toUpperCase();
  threadAvatar.textContent = threadInitial;
  if (chat.peer_avatar_url) {
    const image = document.createElement('img');
    image.className = 'thread-avatar-image';
    image.alt = '';
    image.src = chat.peer_avatar_url;
    image.addEventListener('error', () => { threadAvatar.replaceChildren(); threadAvatar.textContent = threadInitial; }, { once: true });
    threadAvatar.replaceChildren(image);
  }
  clearInterval(pollTimer);
  await loadMessages(true);
  resumeMessagePolling();
  loadChats();
}

function addMessage(message) {
  const messageId = Number(message.id);
  if (Number.isSafeInteger(messageId) && messageId > 0 && document.getElementById(`message-${messageId}`)) return null;
  const row = document.createElement('article');
  if (Number.isSafeInteger(messageId) && messageId > 0) row.id = `message-${messageId}`;
  row.className = `message-row${Number(message.sender_id) === Number(currentUser?.id) ? ' mine' : ''}`;
  const authorLine = document.createElement('div');
  authorLine.className = 'message-author-line';
  if (message.avatar_url) {
    const avatar = document.createElement('img');
    avatar.className = 'message-author-avatar';
    avatar.src = message.avatar_url;
    avatar.alt = '';
    avatar.addEventListener('error', () => avatar.remove(), { once: true });
    authorLine.append(avatar);
  }
  if (!row.classList.contains('mine')) {
    const author = document.createElement('p');
    author.className = 'message-author';
    author.textContent = message.display_name || `@${message.username || ''}`;
    authorLine.append(author);
  }
  if (authorLine.childElementCount) row.append(authorLine);
  const bubble = document.createElement('div');
  bubble.className = 'message-bubble';
  if (message.message_type === 'text') {
    bubble.textContent = message.body || '';
  } else if (message.message_type === 'gif') {
    const image = document.createElement('img');
    image.className = 'message-media';
    image.src = message.media_url;
    image.alt = 'GIF';
    image.loading = 'lazy';
    bubble.append(image);
  } else if (message.message_type === 'sticker') {
    const image = document.createElement('img');
    image.className = 'message-sticker';
    image.src = `/api/stickers/asset.php?id=${encodeURIComponent(message.sticker_id)}`;
    image.alt = message.sticker_alt_text || 'Стикер';
    image.loading = 'lazy';
    bubble.append(image);
  }
  row.append(bubble);
  const time = document.createElement('p');
  time.className = 'message-time';
  const date = new Date(String(message.created_at || '').replace(' ', 'T'));
  time.textContent = Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  row.append(time);
  $('#messages').append(row);
  return row;
}

function prependMessage(message) {
  const list = $('#messages');
  const previousHeight = list.scrollHeight;
  const previousTop = list.scrollTop;
  const row = addMessage(message);
  if (!row) return;
  list.prepend(row);
  list.scrollTop = previousTop + (list.scrollHeight - previousHeight);
}

async function loadOlderMessages() {
  if (!currentConversationId || !oldestMessageId || olderMessagesInFlight || messageRequestInFlight) return;
  const conversationId = currentConversationId;
  const viewToken = conversationViewToken;
  const controller = new AbortController();
  messageRequestController = controller;
  olderMessagesInFlight = true;
  try {
    const { response, data } = await api(`/chats/messages.php?conversation_id=${conversationId}&before_id=${oldestMessageId}`, { headers: {}, signal: controller.signal });
    if (!response.ok || currentConversationId !== conversationId || conversationViewToken !== viewToken) return;
    const items = data.messages || [];
    for (const item of items) prependMessage(item);
    if (items.length) oldestMessageId = Number(items[0].id);
  } catch { /* Keep the current history visible; the next scroll can retry. */ }
  finally {
    if (messageRequestController === controller) messageRequestController = null;
    olderMessagesInFlight = false;
    if (currentConversationId && (currentConversationId !== conversationId || conversationViewToken !== viewToken)) {
      queueMicrotask(() => loadMessages(true));
    }
  }
}

$('#messages').addEventListener('scroll', () => {
  if ($('#messages').scrollTop < 80) loadOlderMessages();
});

async function loadMessages(initial) {
  if (!currentConversationId || document.hidden || messageRequestInFlight || olderMessagesInFlight) return;
  const conversationId = currentConversationId;
  const viewToken = conversationViewToken;
  const messageList = $('#messages');
  const wasNearBottom = messageList.scrollHeight - messageList.scrollTop - messageList.clientHeight < 120;
  const controller = new AbortController();
  messageRequestController = controller;
  messageRequestInFlight = true;
  try {
    const cursor = initial ? '' : `&after_id=${lastMessageId}`;
    const { response, data } = await api(`/chats/messages.php?conversation_id=${conversationId}${cursor}`, { headers: {}, signal: controller.signal });
    if (currentConversationId !== conversationId || conversationViewToken !== viewToken) return;
    if (!response.ok) {
      if (initial) {
        const emptyState = $('#messages-empty');
        if (emptyState) emptyState.textContent = 'Не удалось загрузить сообщения. Проверьте соединение.';
      }
      if (response.status === 403) {
        if (currentConversationKind === 'local' || data.membershipRemoved) {
          const notice = data.error === 'age_group_changed'
            ? 'Возрастная группа изменилась — доступ к переписке закрыт.'
            : data.error === 'local_access_disabled'
              ? 'Подтверждение местоположения истекло или отключено. Переписка закрыта.'
              : 'Вы удалены из переписки: её возрастные группы несовместимы.';
          closeCurrentConversation();
          showAppStatus(notice);
        } else {
          $('#thread-subtitle').textContent = 'Чат ограничен настройками возрастных групп.';
          $('#message-input').disabled = true;
          $('#message-form .send-button').disabled = true;
        }
      } else if (response.status === 404) {
        showAppStatus('Эта переписка больше недоступна. Список чатов обновлён.');
        closeCurrentConversation();
      }
      return;
    }
    const items = data.messages || [];
    for (const item of items) {
      addMessage(item);
      lastMessageId = Math.max(lastMessageId, Number(item.id));
    }
    const emptyState = $('#messages-empty');
    if (emptyState) {
      if (items.length) emptyState.remove();
      else emptyState.textContent = 'Пока нет сообщений — отправьте первое.';
    }
    if (items.length) {
      if (initial) oldestMessageId = Number(items[0].id);
      if (initial || wasNearBottom) messageList.scrollTop = messageList.scrollHeight;
      if (!initial) loadChats();
    }
  } catch {
    if (initial && currentConversationId === conversationId && conversationViewToken === viewToken) {
      const emptyState = $('#messages-empty');
      if (emptyState) emptyState.textContent = 'Не удалось загрузить сообщения. Проверьте соединение.';
    }
  }
  finally {
    if (messageRequestController === controller) messageRequestController = null;
    messageRequestInFlight = false;
    if (currentConversationId && (currentConversationId !== conversationId || conversationViewToken !== viewToken)) {
      queueMicrotask(() => loadMessages(true));
    }
  }
}

function closeCurrentConversation() {
  messageRequestController?.abort();
  clearInterval(pollTimer);
  currentConversationId = null;
  currentConversationKind = null;
  lastMessageId = 0;
  oldestMessageId = 0;
  $('#message-input').disabled = false;
  $('#message-form .send-button').disabled = false;
  $('#active-thread').hidden = true;
  $('#thread-empty').hidden = false;
  chatView.classList.remove('thread-open');
  loadChats();
}

async function sendChatMessage(payload) {
  if (!currentConversationId) return false;
  const conversationId = currentConversationId;
  const viewToken = conversationViewToken;
  const status = $('#message-status');
  status.hidden = true;
  status.textContent = '';
  try {
    const { response, data } = await api('/chats/messages.php', {
      method: 'POST',
      body: JSON.stringify({ conversationId, ...payload }),
    });
    if (!response.ok) {
      if (currentConversationId !== conversationId || conversationViewToken !== viewToken) return false;
      showMessage(status, data.error === 'age_group_restricted'
        ? 'Отправка недоступна из-за настроек возрастных групп.'
        : data.error === 'too_many_messages'
          ? rateLimitMessage(data)
          : 'Не удалось отправить сообщение. Попробуйте ещё раз.');
      status.hidden = false;
      return false;
    }
    const stillViewingConversation = currentConversationId === conversationId && conversationViewToken === viewToken;
    if (stillViewingConversation) {
      const sentId = Number(data.id);
      if (Number.isSafeInteger(sentId) && sentId > lastMessageId) {
        $('#messages-empty')?.remove();
        addMessage({
          id: sentId,
          sender_id: currentUser?.id,
          username: currentUser?.username,
          display_name: currentUser?.displayName,
          avatar_url: currentUser?.avatarUrl,
          message_type: payload.type || 'text',
          body: payload.body || null,
          media_url: payload.url || null,
          sticker_id: payload.stickerId || null,
          created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
        });
        if (!oldestMessageId) oldestMessageId = sentId;
        const messageList = $('#messages');
        messageList.scrollTop = messageList.scrollHeight;
      }
      await loadMessages(false);
    }
    loadChats();
    return stillViewingConversation;
  } catch {
    if (currentConversationId === conversationId && conversationViewToken === viewToken) {
      showMessage(status, 'Нет связи с сервером. Сообщение не отправлено.');
      status.hidden = false;
    }
    return false;
  }
}

$('#message-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const input = $('#message-input');
  const body = input.value.trim();
  if (!body) return;
  const send = event.currentTarget.querySelector('.send-button');
  send.disabled = true;
  try {
    const sent = await sendChatMessage({ type: 'text', body });
    if (sent && input.value.trim() === body) {
      input.value = '';
      input.style.height = '42px';
    }
  } finally { send.disabled = false; input.focus(); }
});

$('#message-input').addEventListener('input', (event) => {
  event.currentTarget.style.height = '42px';
  event.currentTarget.style.height = `${Math.min(event.currentTarget.scrollHeight, 130)}px`;
});
$('#message-input').addEventListener('keydown', (event) => {
  if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); $('#message-form').requestSubmit(); }
});

let createChatKind = 'direct';
function showCreateChat(kind) {
  createChatKind = kind;
  const group = kind === 'group';
  $('#direct-fields').hidden = group;
  $('#group-fields').hidden = !group;
  $('#chat-dialog-title').textContent = group ? 'Создать группу' : 'Кому написать?';
  $('#chat-dialog-eyebrow').textContent = group ? 'Новая группа' : 'Новая переписка';
  $('#chat-create-message').textContent = '';
  $('#chat-dialog').showModal();
}
$('#new-chat').addEventListener('click', () => showCreateChat('direct'));
$('#empty-new-chat').addEventListener('click', () => showCreateChat('direct'));
$('#create-group').addEventListener('click', () => showCreateChat('group'));
$('#chat-dialog').querySelector('.dialog-close').addEventListener('click', () => $('#chat-dialog').close());
$('#chat-create-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const message = $('#chat-create-message');
  let usernames;
  const payload = { kind: createChatKind };
  if (createChatKind === 'group') {
    payload.title = $('#group-title').value.trim();
    usernames = $('#group-members').value.split(',').map((item) => item.trim().replace(/^@/, '')).filter(Boolean);
  } else {
    usernames = [$('#chat-username').value.trim().replace(/^@/, '')];
  }
  if (usernames.some((name) => !/^[a-z0-9_]{3,24}$/i.test(name)) || (createChatKind === 'group' && !payload.title)) {
    showMessage(message, createChatKind === 'group' ? 'Укажите название и корректные имена участников через запятую.' : 'Введите корректное имя пользователя.');
    return;
  }
  payload.usernames = usernames;
  const button = $('#chat-create-submit');
  button.disabled = true;
  try {
    const { response, data } = await api('/chats/index.php', { method: 'POST', body: JSON.stringify(payload) });
    if (!response.ok) {
      showMessage(message, data.error === 'user_not_found' ? 'Пользователь не найден.' : data.error === 'age_group_restricted' ? 'Чат между указанными возрастными группами ограничен.' : 'Не удалось создать чат. Проверьте данные.');
      return;
    }
    $('#chat-dialog').close();
    const title = payload.title || (createChatKind === 'direct' ? (data.peer_name || `@${usernames[0]}`) : payload.title);
    await openConversation(Number(data.conversationId), { kind: createChatKind, title, peer_username: data.peer_username || usernames[0], peer_name: data.peer_name, peer_avatar_url: data.peer_avatar_url });
  } catch { showMessage(message, 'Не удалось связаться с сервером.'); }
  finally { button.disabled = false; }
});

$('#thread-back').addEventListener('click', () => chatView.classList.remove('thread-open'));
$('#leave-chat').addEventListener('click', async () => {
  if (!currentConversationId || !window.confirm('Убрать эту переписку из ваших чатов?')) return;
  const { response } = await api('/chats/leave.php', { method: 'POST', body: JSON.stringify({ conversationId: currentConversationId }) });
  if (!response.ok) return;
  closeCurrentConversation();
});

$('#logout-button').addEventListener('click', async () => {
  try {
    const { response } = await api('/auth/logout.php', { method: 'POST', body: '{}' });
    if (!response.ok) return;
  } catch { return; }
  clearInterval(pollTimer);
  clearInterval(chatRefreshTimer);
  currentUser = null;
  currentConversationId = null;
  showLogin();
});

$('#forgot-password').addEventListener('click', () => {
  $('#reset-email').value = $('#login-email').value.trim();
  $('#reset-fields').hidden = true;
  $('#reset-submit').hidden = true;
  showMessage($('#reset-message'), '');
  $('#reset-dialog').showModal();
});
$('#reset-dialog').querySelector('.dialog-close').addEventListener('click', () => $('#reset-dialog').close());
$('#reset-send-code').addEventListener('click', async () => {
  const email = $('#reset-email');
  if (!email.reportValidity()) return;
  const button = $('#reset-send-code');
  button.disabled = true;
  let cooldownStarted = false;
  try {
    const { response, data } = await api('/auth/request-password-reset.php', {
      method: 'POST', body: JSON.stringify({ email: email.value.trim() }),
    });
    if (!response.ok) {
      const message = response.status === 429
        ? rateLimitMessage(data)
        : data.error === 'invalid_email'
          ? 'Введите корректный адрес электронной почты.'
          : 'Не удалось запросить код. Попробуйте позже.';
      showMessage($('#reset-message'), message);
      return;
    }
    $('#reset-fields').hidden = false;
    $('#reset-submit').hidden = false;
    startPasswordResetCodeCountdown(60);
    cooldownStarted = true;
    showMessage($('#reset-message'), 'Если аккаунт с такой почтой существует, на неё отправлен код. Проверьте также папку «Спам».', true);
  } catch { showMessage($('#reset-message'), 'Не удалось запросить код. Попробуйте позже.'); }
  finally { if (!cooldownStarted) button.disabled = false; }
});
$('#reset-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const code = $('#reset-code').value.trim();
  const password = $('#reset-new-password').value;
  if (!/^\d{6}$/.test(code)) { showMessage($('#reset-message'), 'Введите шестизначный код из письма.'); return; }
  if (!Object.values(passwordRules(password)).every(Boolean)) { showMessage($('#reset-message'), 'Новый пароль должен соответствовать всем требованиям.'); return; }
  if (password !== $('#reset-confirm-password').value) { showMessage($('#reset-message'), 'Пароли не совпадают.'); return; }
  const button = $('#reset-submit'); button.disabled = true;
  try {
    const { response, data } = await api('/auth/reset-password.php', {
      method: 'POST',
      body: JSON.stringify({ email: $('#reset-email').value.trim(), code, password }),
    });
    if (!response.ok) {
      showMessage($('#reset-message'), data.error === 'password_policy' ? 'Пароль не соответствует требованиям.' : 'Код неверный или устарел. Запросите новый.');
      return;
    }
    $('#reset-dialog').close();
    $('#login-email').value = $('#reset-email').value.trim();
    showMessage($('#login-message'), 'Пароль обновлён. Войдите с новым паролем.', true);
  } catch { showMessage($('#reset-message'), 'Не удалось связаться с сервером.'); }
  finally { button.disabled = false; }
});

$('#account-label').addEventListener('click', async () => {
  showAppStatus('');
  let data;
  try {
    const result = await api('/users/profile.php', { headers: {} });
    if (!result.response.ok) {
      showAppStatus('Не удалось загрузить настройки аккаунта. Попробуйте ещё раз.');
      return;
    }
    data = result.data;
    currentUser.ageGroup = data.ageGroup;
  currentUser.avatarUrl = data.avatarUrl || null;
  } catch {
    showAppStatus('Нет связи с сервером. Настройки аккаунта не загрузились.');
    return;
  }
  $('#settings-display-name').value = data.displayName || '';
  $('#settings-bio').value = data.bio || '';
  $('#settings-age').value = data.ageYears ?? '';
  $('#age-group-label').textContent = `Возрастная группа: ${data.ageGroupLabel}. Точный возраст другим не показывается.`;
  if (data.avatarUrl) {
    $('#settings-avatar-preview').src = data.avatarUrl;
    $('#settings-avatar-preview').hidden = false;
    $('#settings-avatar-placeholder').hidden = true;
  } else {
    $('#settings-avatar-preview').removeAttribute('src');
    $('#settings-avatar-preview').hidden = true;
    $('#settings-avatar-placeholder').hidden = false;
  }
  $('#location-city').value = data.locationCity || '';
  showMessage($('#location-message'), data.locationEnabled
    ? 'Местная зона подтверждена и действует 24 часа.'
    : data.locationCity
      ? 'Подтверждение зоны истекло. Обновите его, чтобы пользоваться местным чатом.'
      : 'Геолокация выключена.');
  await loadDevices();
  $('#profile-dialog').showModal();
});

$('#settings-avatar-file').addEventListener('change', async () => {
  const input = $('#settings-avatar-file');
  const file = input.files?.[0];
  if (!file) return;
  if (file.size > 4 * 1024 * 1024) {
    showMessage($('#profile-settings-message'), 'Аватар должен быть не больше 4 МБ.');
    input.value = '';
    return;
  }
  input.disabled = true;
  const previousAvatarUrl = currentUser?.avatarUrl || '';
  const previewUrl = URL.createObjectURL(file);
  $('#settings-avatar-preview').src = previewUrl;
  $('#settings-avatar-preview').hidden = false;
  $('#settings-avatar-placeholder').hidden = true;
  const form = new FormData(); form.append('avatar', file);
  try {
    const { response, data } = await api('/users/avatar-upload.php', { method: 'POST', body: form });
    if (!response.ok) {
      if (previousAvatarUrl) {
        $('#settings-avatar-preview').src = previousAvatarUrl;
        $('#settings-avatar-preview').hidden = false;
        $('#settings-avatar-placeholder').hidden = true;
      } else {
        $('#settings-avatar-preview').removeAttribute('src');
        $('#settings-avatar-preview').hidden = true;
        $('#settings-avatar-placeholder').hidden = false;
      }
      showMessage($('#profile-settings-message'), 'Не удалось обновить аватар. Выберите PNG, JPEG или WebP до 4 МБ.');
      return;
    }
    $('#settings-avatar-preview').src = data.avatarUrl;
    currentUser.avatarUrl = data.avatarUrl;
    showMessage($('#profile-settings-message'), 'Аватар обновлён.', true);
  } catch {
    if (previousAvatarUrl) {
      $('#settings-avatar-preview').src = previousAvatarUrl;
      $('#settings-avatar-preview').hidden = false;
      $('#settings-avatar-placeholder').hidden = true;
    } else {
      $('#settings-avatar-preview').removeAttribute('src');
      $('#settings-avatar-preview').hidden = true;
      $('#settings-avatar-placeholder').hidden = false;
    }
    showMessage($('#profile-settings-message'), 'Не удалось связаться с сервером. Аватар не обновлён.');
  } finally {
    URL.revokeObjectURL(previewUrl);
    input.value = '';
    input.disabled = false;
  }
});

async function loadDevices() {
  const list = $('#device-list');
  list.replaceChildren();
  try {
    const { response, data } = await api('/users/devices.php', { headers: {} });
    if (!response.ok) { list.textContent = 'Не удалось загрузить список устройств.'; return; }
    for (const device of data.devices || []) {
      const item = document.createElement('div'); item.className = 'device-item';
      const label = document.createElement('div');
      const name = document.createElement('strong'); name.textContent = device.isCurrent ? 'Это устройство' : 'Устройство';
      const detail = document.createElement('span'); detail.textContent = device.deviceLabel || 'Браузер';
      label.append(name, detail);
      item.append(label);
      if (!device.isCurrent) {
        const revoke = document.createElement('button'); revoke.type = 'button'; revoke.className = 'text-button danger-button'; revoke.textContent = 'Выйти';
        revoke.addEventListener('click', async () => {
          try {
            const { response: revokeResponse } = await api(`/users/devices.php?id=${device.id}`, { method: 'DELETE', headers: {} });
            if (revokeResponse.ok) await loadDevices();
            else showAppStatus('Не удалось отозвать доступ этого устройства.');
          } catch { showAppStatus('Нет связи с сервером. Доступ устройства не отозван.'); }
        });
        item.append(revoke);
      }
      list.append(item);
    }
    if (!list.children.length) list.textContent = 'Нет активных устройств.';
  } catch {
    list.textContent = 'Нет связи с сервером. Список устройств не загрузился.';
  }
}
$('#profile-dialog').querySelector('.dialog-close').addEventListener('click', () => $('#profile-dialog').close());
$('#profile-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!event.currentTarget.reportValidity()) return;
  const value = $('#settings-age').value;
  const button = $('#save-profile');
  button.disabled = true;
  try {
    const { response, data } = await api('/users/profile.php', {
      method: 'POST',
      body: JSON.stringify({
        displayName: $('#settings-display-name').value.trim(),
        bio: $('#settings-bio').value.trim(),
        ageYears: value === '' ? null : Number(value),
      }),
    });
    if (!response.ok) {
      showMessage($('#profile-settings-message'), 'Не удалось сохранить профиль. Проверьте возраст и имя.');
      return;
    }
    currentUser.displayName = $('#settings-display-name').value.trim();
    currentUser.ageGroup = data.ageGroup;
    $('#age-group-label').textContent = `Возрастная группа: ${data.ageGroupLabel}. Точный возраст другим не показывается.`;
    const currentChatRemoved = (Array.isArray(data.removedConversationIds)
      && data.removedConversationIds.map(Number).includes(Number(currentConversationId)))
      || (currentConversationKind === 'local' && Number(data.removedFromLocalChats) > 0);
    const profileNotices = [];
    if (Number(data.removedFromChats) > 0) profileNotices.push('Вы вышли из переписок с несовместимой возрастной группой');
    if (Number(data.removedFromLocalChats) > 0) profileNotices.push('Для местного чата нужно подключиться заново');
    showAppStatus(profileNotices.length ? `Профиль сохранён. ${profileNotices.join('. ')}.` : 'Профиль сохранён.', true);
    if (currentChatRemoved) closeCurrentConversation();
    $('#profile-dialog').close();
    $('#account-label').textContent = currentUser.username ? `@${currentUser.username}` : currentUser.email;
    if (profileNotices.length) loadChats();
  } catch {
    showMessage($('#profile-settings-message'), 'Нет связи с сервером. Профиль не сохранён.');
  } finally {
    button.disabled = false;
  }
});

$('#enable-location').addEventListener('click', () => {
  const city = $('#location-city').value.trim();
  const message = $('#location-message');
  if (!city) { showMessage(message, 'Укажите город для местного чата.'); return; }
  if (!navigator.geolocation) { showMessage(message, 'Этот браузер не поддерживает геолокацию.'); return; }
  showMessage(message, 'Запрашиваем разрешение у устройства…');
  navigator.geolocation.getCurrentPosition(async (position) => {
    try {
      const { response, data } = await api('/users/location.php', {
        method: 'POST',
        body: JSON.stringify({ city, latitude: position.coords.latitude, longitude: position.coords.longitude }),
      });
      if (!response.ok) {
        showMessage(message, data.error === 'location_not_configured' ? 'На сервере не настроен ключ для безопасного хранения зоны.' : 'Не удалось включить местный чат.');
        return;
      }
      showMessage(message, `Геолокация включена для зоны «${data.locationCity || city}». Точные координаты не сохранены.`, true);
      if (currentConversationKind === 'local') closeCurrentConversation();
      await loadChats();
    } catch { showMessage(message, 'Не удалось связаться с сервером.'); }
  }, (error) => {
    showMessage(message, error.code === 1 ? 'Разрешите доступ к геолокации в настройках устройства.' : 'Не удалось получить местоположение. Попробуйте ещё раз.');
  }, { enableHighAccuracy: false, timeout: 12000, maximumAge: 60000 });
});

$('#disable-location').addEventListener('click', async () => {
  try {
    const { response } = await api('/users/location.php', { method: 'DELETE', headers: {} });
    showMessage($('#location-message'), response.ok ? 'Геолокация выключена, точная зона удалена.' : 'Не удалось выключить геолокацию.', response.ok);
    if (response.ok) {
      if (currentConversationKind === 'local') closeCurrentConversation();
      await loadChats();
    }
  } catch { showMessage($('#location-message'), 'Нет связи с сервером. Настройка геолокации не изменена.'); }
});

$('#join-local').addEventListener('click', async () => {
  try {
    const { response, data } = await api('/chats/local.php', { method: 'POST', body: '{}' });
    if (!response.ok) {
      showMessage($('#location-message'), 'Сначала включите геолокацию в настройках аккаунта.');
      $('#account-label').click();
      return;
    }
    await openConversation(Number(data.conversationId), { kind: 'local', title: data.title, subtitle: `Возрастная группа: ${data.ageGroupLabel}` });
  } catch { showAppStatus('Нет связи с сервером. Не удалось подключиться к местному чату.'); }
});

async function loadGiphyKey() {
  try {
    const { response, data } = await api('/config.php', { headers: {} });
    if (response.ok) giphyKey = data.giphyApiKey;
  } catch { giphyKey = null; }
}

async function searchGifs(query = '') {
  const sequence = ++gifSearchSequence;
  const results = $('#gif-results');
  results.replaceChildren();
  if (!giphyKey) {
    results.textContent = 'GIF-поиск пока не настроен.';
    return;
  }
  const endpoint = query ? 'search' : 'trending';
  const ageRating = ({ under_16: 'g', '16_17': 'pg', adult: 'pg-13' })[currentUser?.ageGroup] || 'g';
  const params = new URLSearchParams({ api_key: giphyKey, rating: ageRating, lang: 'ru', limit: '24', bundle: 'messaging_non_clips' });
  if (query) params.set('q', query.slice(0, 50));
  try {
    const response = await fetch(`https://api.giphy.com/v1/gifs/${endpoint}?${params}`);
    if (!response.ok) throw new Error('GIPHY unavailable');
    const data = await response.json();
    if (sequence !== gifSearchSequence) return;
    for (const gif of data.data || []) {
      const url = gif.images?.fixed_width?.url || gif.images?.downsized?.url;
      const sendUrl = gif.images?.downsized_medium?.url || gif.images?.original?.url || url;
      if (!url || !sendUrl) continue;
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'gif-result'; button.title = gif.title || 'Отправить GIF';
      const image = document.createElement('img'); image.src = url; image.alt = gif.title || 'GIF'; image.loading = 'lazy'; button.append(image);
      if (gif.analytics?.on_load?.url) fetch(gif.analytics.on_load.url, { mode: 'no-cors' }).catch(() => {});
      button.addEventListener('click', async () => {
        const analytics = gif.analytics?.on_click?.url;
        if (analytics) fetch(analytics, { mode: 'no-cors' }).catch(() => {});
        if (await sendChatMessage({ type: 'gif', url: sendUrl })) $('#media-panel').hidden = true;
      });
      results.append(button);
    }
    if (!results.children.length) results.textContent = 'Ничего не найдено.';
  } catch {
    if (sequence === gifSearchSequence) results.textContent = 'Не удалось загрузить GIF. Попробуйте позже.';
  }
}

$('#gif-search').addEventListener('input', () => {
  clearTimeout(gifSearchTimer);
  gifSearchTimer = setTimeout(() => searchGifs($('#gif-search').value.trim()), 300);
});
$('#open-stickers').addEventListener('click', async () => {
  $('#media-panel').hidden = !$('#media-panel').hidden;
  if (!$('#media-panel').hidden) {
    await loadGiphyKey();
    await searchGifs($('#gif-search').value.trim());
  }
});
$('#close-media').addEventListener('click', () => { $('#media-panel').hidden = true; });
$('#gif-tab').addEventListener('click', () => {
  $('#gif-tab').classList.add('active'); $('#sticker-tab').classList.remove('active');
  $('#gif-panel').hidden = false; $('#sticker-panel').hidden = true;
});
$('#sticker-tab').addEventListener('click', async () => {
  $('#sticker-tab').classList.add('active'); $('#gif-tab').classList.remove('active');
  $('#sticker-panel').hidden = false; $('#gif-panel').hidden = true;
  await loadPacks();
});

async function loadPacks() {
  try {
    const { response, data } = await api('/stickers/packs.php', { headers: {} });
    if (!response.ok) {
      showMessage($('#sticker-action-message'), 'Не удалось загрузить стикерпаки. Попробуйте ещё раз.');
      return false;
    }
    availablePacks = data.packs || [];
    if (!availablePacks.some((pack) => Number(pack.id) === selectedPackId)) selectedPackId = availablePacks[0]?.id || null;
    renderPacks();
    return true;
  } catch {
    showMessage($('#sticker-action-message'), 'Нет связи с сервером. Не удалось загрузить стикерпаки.');
    return false;
  }
}

function renderPacks() {
  const tabs = $('#pack-tabs'); tabs.replaceChildren();
  const results = $('#sticker-results'); results.replaceChildren();
  const actions = $('#pack-actions'); actions.replaceChildren();
  showMessage($('#sticker-action-message'), '');
  $('#sticker-empty').hidden = availablePacks.length > 0;
  for (const pack of availablePacks) {
    const tab = document.createElement('button');
    tab.type = 'button'; tab.className = `pack-tab${Number(pack.id) === Number(selectedPackId) ? ' active' : ''}`; tab.textContent = pack.title;
    tab.addEventListener('click', () => { selectedPackId = Number(pack.id); renderPacks(); });
    tabs.append(tab);
  }
  const pack = availablePacks.find((item) => Number(item.id) === Number(selectedPackId));
  if (!pack) return;
  if (!pack.isOwner && !pack.isInstalled) {
    const install = document.createElement('button'); install.type = 'button'; install.className = 'outline-button'; install.textContent = 'Установить стикерпак';
    install.addEventListener('click', async () => {
      install.disabled = true;
      try {
        const { response } = await api('/stickers/install.php', { method: 'POST', body: JSON.stringify({ packId: pack.id }) });
        if (response.ok) {
          if (await loadPacks()) showMessage($('#sticker-action-message'), 'Стикерпак добавлен.', true);
          else showAppStatus('Стикерпак добавлен, но список пока не обновился. Откройте его позже.');
        } else showMessage($('#sticker-action-message'), 'Не удалось установить стикерпак.');
      } catch { showMessage($('#sticker-action-message'), 'Нет связи с сервером. Попробуйте ещё раз.'); }
      finally { install.disabled = false; }
    });
    actions.append(install);
  } else if (!pack.isOwner && pack.isInstalled) {
    const uninstall = document.createElement('button'); uninstall.type = 'button'; uninstall.className = 'text-button danger-button'; uninstall.textContent = 'Убрать из моих паков';
    uninstall.addEventListener('click', async () => {
      uninstall.disabled = true;
      try {
        const { response } = await api('/stickers/install.php', { method: 'DELETE', body: JSON.stringify({ packId: pack.id }) });
        if (!response.ok) {
          showMessage($('#sticker-action-message'), 'Не удалось убрать стикерпак из библиотеки.');
          return;
        }
        if (await loadPacks()) showMessage($('#sticker-action-message'), 'Пак убран. Стикеры в истории чатов останутся доступны.', true);
        else showAppStatus('Пак убран, но список стикеров пока не обновился.');
      } catch { showMessage($('#sticker-action-message'), 'Нет связи с сервером. Пак не удалён.'); }
      finally { uninstall.disabled = false; }
    });
    actions.append(uninstall);
  }
  if (pack.isOwner) {
    const add = document.createElement('button'); add.type = 'button'; add.className = 'text-button'; add.textContent = '＋ Добавить стикеры';
    add.addEventListener('click', () => {
      const input = document.createElement('input'); input.type = 'file'; input.accept = 'image/png,image/webp,image/gif'; input.multiple = true;
      input.addEventListener('change', async () => {
        const files = Array.from(input.files || []);
        if (!files.length) return;
        add.disabled = true;
        showMessage($('#sticker-action-message'), `Загружаем стикеры: 0 из ${files.length}…`);
        let added = 0;
        let failed = 0;
        let uploadWarning = '';
        for (const [index, file] of files.entries()) {
          try {
            const form = new FormData(); form.append('packId', String(pack.id)); form.append('sticker', file);
            const { response, data } = await api('/stickers/add.php', { method: 'POST', body: form });
            if (response.ok) added += 1;
            else {
              failed += 1;
              if (data.error === 'pack_sticker_limit') {
                uploadWarning = 'В одном паке может быть не больше 100 стикеров.';
                break;
              }
              if (data.error === 'too_many_sticker_uploads') {
                uploadWarning = 'Достигнут часовой лимит загрузки. Попробуйте позже.';
                break;
              }
            }
          } catch { failed += 1; }
          showMessage($('#sticker-action-message'), `Загружаем стикеры: ${index + 1} из ${files.length}…`);
        }
        add.disabled = false;
        await loadPacks();
        showMessage($('#sticker-action-message'), uploadWarning
          ? `Загружено ${added}. ${uploadWarning}`
          : failed
          ? `Загружено ${added} из ${files.length}. ${failed} не удалось загрузить (проверьте формат и лимит 2 МБ).`
          : `Загружено стикеров: ${added}.`, failed === 0);
      });
      input.click();
    });
    actions.append(add);
    const removePack = document.createElement('button'); removePack.type = 'button'; removePack.className = 'text-button danger-button'; removePack.textContent = 'Удалить пак';
    removePack.addEventListener('click', async () => {
      if (!window.confirm(`Удалить стикерпак «${pack.title}»?`)) return;
      removePack.disabled = true;
      try {
        const { response, data } = await api(`/stickers/packs.php?id=${pack.id}`, { method: 'DELETE', headers: {} });
        if (!response.ok) {
          showMessage($('#sticker-action-message'), data.error === 'pack_in_use' ? 'Пак нельзя удалить: его стикеры уже отправляли в чаты.' : 'Не удалось удалить пак.');
          return;
        }
        selectedPackId = null;
        await loadPacks();
      } catch { showMessage($('#sticker-action-message'), 'Нет связи с сервером. Пак не удалён.'); }
      finally { removePack.disabled = false; }
    });
    actions.append(removePack);
  }
  if (!pack.isOwner && !pack.isInstalled) {
    $('#sticker-empty').hidden = false;
    $('#sticker-empty').textContent = 'Установите этот набор, чтобы отправлять его стикеры.';
    return;
  }
  $('#sticker-empty').hidden = (pack.stickers || []).length > 0;
  $('#sticker-empty').textContent = 'В этом стикерпаке пока нет стикеров.';
  for (const sticker of pack.stickers || []) {
    const button = document.createElement('button'); button.type = 'button'; button.className = 'sticker-result'; button.title = sticker.altText || 'Отправить стикер';
    const image = document.createElement('img'); image.src = sticker.assetUrl; image.alt = sticker.altText || ''; image.loading = 'lazy'; button.append(image);
    button.addEventListener('click', async () => { if (await sendChatMessage({ type: 'sticker', stickerId: sticker.id })) $('#media-panel').hidden = true; });
    if (pack.isOwner) {
      const holder = document.createElement('div'); holder.className = 'sticker-admin-item'; holder.append(button);
      const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'sticker-remove'; remove.textContent = '×'; remove.title = 'Удалить стикер';
      remove.addEventListener('click', async () => {
        remove.disabled = true;
        try {
          const { response, data } = await api(`/stickers/add.php?id=${sticker.id}`, { method: 'DELETE', headers: {} });
          if (!response.ok) {
            showMessage($('#sticker-action-message'), data.error === 'sticker_in_use' ? 'Стикер уже отправлен в чат и сохранён в истории.' : 'Не удалось удалить стикер.');
            return;
          }
          await loadPacks();
        } catch { showMessage($('#sticker-action-message'), 'Нет связи с сервером. Стикер не удалён.'); }
        finally { remove.disabled = false; }
      });
      holder.append(remove);
      results.append(holder);
    } else results.append(button);
  }
}

$('#create-pack').addEventListener('click', () => $('#pack-dialog').showModal());
$('#pack-dialog').querySelector('.dialog-close').addEventListener('click', () => $('#pack-dialog').close());
$('#pack-create-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const title = $('#pack-title').value.trim();
  const message = $('#pack-create-message');
  if (!title) { showMessage(message, 'Введите название стикерпака.'); return; }
  const button = $('#pack-create-form').querySelector('.submit-button');
  button.disabled = true;
  try {
    const { response, data } = await api('/stickers/packs.php', {
      method: 'POST',
      body: JSON.stringify({ title, description: $('#pack-description').value.trim(), isPublic: $('#pack-public').checked }),
    });
    if (!response.ok) { showMessage(message, 'Не удалось создать пак.'); return; }
    $('#pack-dialog').close();
    $('#pack-create-form').reset();
    if (!(await loadPacks())) {
      showAppStatus('Стикерпак создан, но список пока не обновился. Откройте стикеры позже.');
      return;
    }
    selectedPackId = Number(data.packId);
    renderPacks();
  } catch { showMessage(message, 'Нет связи с сервером. Пак не создан.'); }
  finally { button.disabled = false; }
});

restoreSession();
