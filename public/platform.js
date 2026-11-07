(() => {
  'use strict';
  const root = document.querySelector('#chat-view');
  if (!root) return;
  const layout = root.querySelector('.messenger-layout');
  const topbar = root.querySelector('.messenger-topbar');
  if (!layout || !topbar) return;

  const nav = document.createElement('nav');
  nav.className = 'platform-nav';
  nav.setAttribute('aria-label', 'Разделы Плюс Чат');
  const tabDefinitions = [
    ['chats', 'Чаты', 'message-circle'],
    ['calls', 'Звонки', 'phone'],
    ['feed', 'Лента', 'newspaper'],
    ['profile', 'Профиль', 'user-round'],
  ];
  const buttons = new Map();
  for (const [id, label, symbol] of tabDefinitions) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'platform-tab';
    button.dataset.tab = id;
    button.innerHTML = '<span class="platform-tab-symbol" aria-hidden="true"></span><span></span>';
    button.querySelector('.platform-tab-symbol').textContent = ({chats:'◉',calls:'☎',feed:'▤'})[id] || symbol;
    button.querySelector('span:last-child').textContent = label;
    button.addEventListener('click', () => {
      if (id === 'profile') {
        document.querySelector('#account-label')?.click();
        return;
      }
      showTab(id);
    });
    nav.append(button);
    buttons.set(id, button);
  }
  topbar.insertAdjacentElement('afterend', nav);

  // Extend the existing plus menu with channel publishing shortcuts.
  const createMenu = root.querySelector('#new-chat-menu');
  if (createMenu) {
    const channelAction = document.createElement('button');
    channelAction.type = 'button';
    channelAction.dataset.platformAction = 'channel';
    channelAction.textContent = 'Создать канал';
    channelAction.addEventListener('click', () => {
      showTab('feed');
      window.setTimeout(() => document.querySelector('#platform-channel-title')?.focus(), 0);
      createMenu.hidden = true;
      root.querySelector('#new-chat')?.setAttribute('aria-expanded', 'false');
    });
    const postAction = document.createElement('button');
    postAction.type = 'button';
    postAction.dataset.platformAction = 'post';
    postAction.textContent = 'Новая публикация';
    postAction.addEventListener('click', () => {
      showTab('feed');
      window.setTimeout(() => {
        const channel = document.querySelector('#platform-post-channel');
        if (channel && channel.options.length > 1) channel.focus();
        else document.querySelector('#platform-channel-title')?.focus();
      }, 0);
      createMenu.hidden = true;
      root.querySelector('#new-chat')?.setAttribute('aria-expanded', 'false');
    });
    createMenu.append(channelAction, postAction);
  }

  // Fast client-side chat search: filters the already loaded conversation list.
  const sidebar = root.querySelector('.chat-sidebar');
  const sidebarHeading = sidebar?.querySelector('.sidebar-heading');
  const chatList = sidebar?.querySelector('#chat-list');
  if (sidebar && sidebarHeading && chatList) {
    const searchWrap = document.createElement('label');
    searchWrap.className = 'chat-search';
    searchWrap.setAttribute('aria-label', 'Поиск по чатам');
    searchWrap.innerHTML = '<span aria-hidden="true">⌕</span><input id="chat-search-input" type="search" placeholder="Поиск по чатам" autocomplete="off"><button type="button" aria-label="Очистить поиск" hidden>×</button>';
    sidebarHeading.insertAdjacentElement('afterend', searchWrap);
    const searchInput = searchWrap.querySelector('input');
    const clearSearch = searchWrap.querySelector('button');
    const applyChatFilter = () => {
      const query = searchInput.value.trim().toLocaleLowerCase('ru');
      let visible = 0;
      for (const item of chatList.querySelectorAll('.chat-item')) {
        const matches = !query || item.textContent.toLocaleLowerCase('ru').includes(query);
        item.hidden = !matches;
        if (matches) visible += 1;
      }
      clearSearch.hidden = !query;
      let empty = sidebar.querySelector('.chat-search-empty');
      if (!empty) {
        empty = document.createElement('p');
        empty.className = 'chat-search-empty';
        empty.textContent = 'Ничего не найдено. Попробуйте другое имя.';
        chatList.insertAdjacentElement('afterend', empty);
      }
      empty.hidden = !query || visible > 0;
    };
    searchInput.addEventListener('input', applyChatFilter);
    clearSearch.addEventListener('click', () => {
      searchInput.value = '';
      applyChatFilter();
      searchInput.focus();
    });
    new MutationObserver(applyChatFilter).observe(chatList, {childList: true, subtree: true});
  }

  const callsPanel = document.createElement('section');
  callsPanel.className = 'platform-page';
  callsPanel.id = 'platform-calls';
  callsPanel.hidden = true;
  callsPanel.innerHTML = '<div class="platform-page-heading"><div><p class="eyebrow">Связь с людьми</p><h2>Звонки</h2><p>Выберите собеседника из своих чатов и начните аудио- или видеозвонок.</p></div></div><div class="platform-call-list" id="platform-call-list"><p class="platform-muted">Загружаем контакты…</p></div>';

  const feedPanel = document.createElement('section');
  feedPanel.className = 'platform-page';
  feedPanel.id = 'platform-feed';
  feedPanel.hidden = true;
  feedPanel.innerHTML = '<div class="platform-page-heading"><div><p class="eyebrow">Ваши подписки</p><h2>Лента</h2><p>Публикации открытых каналов, на которые вы подписаны.</p></div></div><div class="platform-feed-layout"><section class="platform-feed-main"><form id="platform-post-form" class="platform-composer"><label for="platform-post-channel">Опубликовать в своём канале</label><select id="platform-post-channel" required><option value="">Сначала создайте канал</option></select><textarea id="platform-post-body" maxlength="5000" rows="3" placeholder="Что нового?" required></textarea><button class="submit-button" type="submit"><span>Опубликовать</span><span aria-hidden="true">↑</span></button><p id="platform-post-status" class="form-message" role="status"></p></form><div id="platform-posts" class="platform-posts"><p class="platform-muted">Загружаем ленту…</p></div></section><aside class="platform-channel-side"><div class="platform-side-heading"><h3>Открытые каналы</h3></div><form id="platform-channel-form" class="platform-channel-form"><strong>Создать канал</strong><input id="platform-channel-title" maxlength="100" placeholder="Название канала" required><input id="platform-channel-slug" maxlength="48" placeholder="адрес_канала" pattern="[a-z0-9][a-z0-9_-]{2,47}" required><textarea id="platform-channel-description" maxlength="500" rows="2" placeholder="Описание (необязательно)"></textarea><button class="outline-button" type="submit">Создать канал</button><p id="platform-channel-status" class="form-message" role="status"></p></form><div id="platform-channels" class="platform-channels"><p class="platform-muted">Загружаем каналы…</p></div></aside></div>';

  layout.insertAdjacentElement('afterend', callsPanel);
  callsPanel.insertAdjacentElement('afterend', feedPanel);

  let activeTab = 'chats';
  let cachedChannels = [];
  const api = async (path, options = {}) => {
    const response = await fetch('./api/' + path, {
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', ...(options.headers || {})},
      ...options,
    });
    const data = await response.json().catch(() => ({}));
    return {response, data};
  };
  const statusText = (selector, text, success = false) => {
    const node = document.querySelector(selector);
    if (!node) return;
    node.textContent = text;
    node.classList.toggle('success', success);
  };
  const make = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  function showTab(id) {
    activeTab = id;
    for (const [key, button] of buttons) {
      button.classList.toggle('active', key === id);
      button.setAttribute('aria-current', key === id ? 'page' : 'false');
    }
    layout.hidden = id !== 'chats';
    callsPanel.hidden = id !== 'calls';
    feedPanel.hidden = id !== 'feed';
    root.classList.toggle('platform-page-open', id !== 'chats');
    if (id !== 'chats' && typeof closeCurrentConversation === 'function') closeCurrentConversation();
    if (id === 'calls') loadCallContacts();
    if (id === 'feed') loadFeedArea();
  }

  async function loadCallContacts() {
    const list = document.querySelector('#platform-call-list');
    list.replaceChildren(make('p', 'platform-muted', 'Загружаем чаты…'));
    try {
      const {response, data} = await api('chats/index.php', {headers: {}});
      if (!response.ok) throw new Error('Не удалось загрузить чаты.');
      list.replaceChildren();
      const chats = data.chats || [];
      if (!chats.length) {
        list.append(make('p', 'platform-muted', 'Сначала начните личный чат или создайте группу — затем сможете позвонить.'));
        return;
      }
      for (const chat of chats) {
        const card = make('article', 'platform-call-card');
        const info = make('div', 'platform-call-info');
        info.append(make('strong', '', chat.title || chat.peer_name || 'Чат'));
        info.append(make('span', '', chat.kind === 'group' ? 'Групповой чат' : (chat.peer_username ? '@' + chat.peer_username : 'Личный чат')));
        const actions = make('div', 'platform-call-actions');
        const call = make('button', 'submit-button', '☎ Позвонить');
        call.type = 'button';
        call.title = 'Начать единый аудио-видеозвонок';
        call.addEventListener('click', async () => {
          call.disabled = true;
          try {
            // startCall opens the call tab synchronously from this click and requests a
            // video-capable room; microphone audio is available in the same call.
            await window.startCall(Number(chat.id));
          } finally {
            call.disabled = false;
          }
        });
        actions.append(call);
        card.append(info, actions);
        list.append(card);
      }
    } catch (error) {
      list.replaceChildren(make('p', 'platform-muted', error.message || 'Не удалось загрузить чаты.'));
    }
  }

  function showTabWithoutReload(id) {
    activeTab = id;
    for (const [key, button] of buttons) {
      button.classList.toggle('active', key === id);
      button.setAttribute('aria-current', key === id ? 'page' : 'false');
    }
    layout.hidden = id !== 'chats';
    callsPanel.hidden = id !== 'calls';
    feedPanel.hidden = id !== 'feed';
    root.classList.toggle('platform-page-open', id !== 'chats');
  }

  async function loadFeedArea() {
    await Promise.all([loadPosts(), loadChannels()]);
  }

  async function loadPosts() {
    const list = document.querySelector('#platform-posts');
    list.replaceChildren(make('p', 'platform-muted', 'Загружаем публикации…'));
    try {
      const {response, data} = await api('channels/feed.php', {headers: {}});
      if (!response.ok) throw new Error('Не удалось загрузить ленту. Проверьте, что миграция каналов установлена.');
      list.replaceChildren();
      const posts = data.posts || [];
      if (!posts.length) {
        list.append(make('div', 'platform-empty', 'Пока в ленте нет публикаций. Подпишитесь на открытые каналы справа — их новые сообщения появятся здесь.'));
        return;
      }
      for (const post of posts) {
        const card = make('article', 'platform-post-card');
        const header = make('div', 'platform-post-header');
        const author = make('div', 'platform-post-author');
        author.append(make('strong', '', post.channel_title || 'Канал'));
        author.append(make('span', '', '@' + (post.channel_slug || 'channel') + ' · ' + (post.author_name || post.author_username || 'Автор')));
        header.append(author);
        const date = make('time', 'platform-post-date', post.created_at || '');
        header.append(date);
        card.append(header);
        if (post.body) card.append(make('p', 'platform-post-body', post.body));
        list.append(card);
      }
    } catch (error) {
      list.replaceChildren(make('p', 'platform-muted', error.message || 'Не удалось загрузить ленту.'));
    }
  }

  async function loadChannels() {
    const list = document.querySelector('#platform-channels');
    const select = document.querySelector('#platform-post-channel');
    list.replaceChildren(make('p', 'platform-muted', 'Загружаем каналы…'));
    try {
      const {response, data} = await api('channels/index.php', {headers: {}});
      if (!response.ok) throw new Error('Не удалось загрузить каналы. Установите миграцию database/pluschat/update_001.sql.');
      cachedChannels = data.channels || [];
      list.replaceChildren();
      select.replaceChildren();
      const owned = cachedChannels.filter(channel => channel.is_owner);
      if (owned.length) {
        select.append(new Option('Выберите свой канал', ''));
        for (const channel of owned) select.append(new Option(channel.title, String(channel.id)));
      } else {
        select.append(new Option('Сначала создайте канал', ''));
      }
      if (!cachedChannels.length) {
        list.append(make('p', 'platform-muted', 'Публичных каналов пока нет. Создайте первый канал.'));
        return;
      }
      for (const channel of cachedChannels) {
        const item = make('article', 'platform-channel-item');
        const details = make('div', 'platform-channel-details');
        details.append(make('strong', '', channel.title));
        details.append(make('span', '', '@' + channel.slug + ' · ' + channel.subscriber_count + ' подписчиков'));
        if (channel.description) details.append(make('small', '', channel.description));
        const button = make('button', 'outline-button channel-subscribe', channel.is_owner ? 'Ваш канал' : channel.is_subscribed ? 'Вы подписаны' : 'Подписаться');
        button.disabled = Boolean(channel.is_owner);
        if (!channel.is_owner) button.addEventListener('click', async () => {
          button.disabled = true;
          const action = channel.is_subscribed ? 'unsubscribe' : 'subscribe';
          try {
            const result = await api('channels/subscribe.php', {method: 'POST', body: JSON.stringify({channel_id: channel.id, action})});
            if (!result.response.ok) throw new Error('Не удалось изменить подписку.');
            await Promise.all([loadChannels(), loadPosts()]);
          } catch (error) {
            statusText('#platform-channel-status', error.message || 'Ошибка подписки.');
            button.disabled = false;
          }
        });
        const openChannel = make('a', 'platform-channel-open', 'Открыть канал →');
        openChannel.href = './channel/index.php?slug=' + encodeURIComponent(channel.slug);
        openChannel.setAttribute('aria-label', 'Открыть канал ' + channel.title);
        item.append(details, openChannel, button);
        list.append(item);
      }
    } catch (error) {
      list.replaceChildren(make('p', 'platform-muted', error.message || 'Не удалось загрузить каналы.'));
    }
  }

  document.querySelector('#platform-channel-form').addEventListener('submit', async event => {
    event.preventDefault();
    const title = document.querySelector('#platform-channel-title').value.trim();
    const slug = document.querySelector('#platform-channel-slug').value.trim().toLowerCase();
    const description = document.querySelector('#platform-channel-description').value.trim();
    const button = event.currentTarget.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const {response, data} = await api('channels/index.php', {method: 'POST', body: JSON.stringify({title, slug, description})});
      if (!response.ok) throw new Error(data.error === 'channel_slug_taken' ? 'Этот адрес канала уже занят.' : 'Не удалось создать канал. Проверьте название и адрес.');
      event.currentTarget.reset();
      statusText('#platform-channel-status', 'Канал создан. Теперь можно публиковать записи.', true);
      await loadChannels();
    } catch (error) {
      statusText('#platform-channel-status', error.message || 'Не удалось создать канал.');
    } finally {
      button.disabled = false;
    }
  });

  document.querySelector('#platform-post-form').addEventListener('submit', async event => {
    event.preventDefault();
    const channelId = Number(document.querySelector('#platform-post-channel').value);
    const body = document.querySelector('#platform-post-body').value.trim();
    if (!channelId || !body) return;
    const button = event.currentTarget.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const {response} = await api('channels/posts.php', {method: 'POST', body: JSON.stringify({channel_id: channelId, body})});
      if (!response.ok) throw new Error('Не удалось опубликовать запись.');
      document.querySelector('#platform-post-body').value = '';
      statusText('#platform-post-status', 'Публикация добавлена в канал.', true);
      await loadPosts();
    } catch (error) {
      statusText('#platform-post-status', error.message || 'Не удалось опубликовать запись.');
    } finally {
      button.disabled = false;
    }
  });

  showTab('chats');
})();