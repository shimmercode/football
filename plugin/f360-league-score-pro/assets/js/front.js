(function(){
  'use strict';

  function norm(s){
    return (s || '').toString().trim().toLowerCase();
  }

  function ready(fn){
    if(document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  function getConfig(){
    return window.F360LS || {};
  }

  function ajaxRequest(data){
    var cfg = getConfig();

    if(!cfg.ajaxUrl) {
      return Promise.reject(new Error('Missing ajaxUrl'));
    }

    var fd = new FormData();

    Object.keys(data || {}).forEach(function(key){
      fd.append(key, data[key]);
    });

    fd.append('nonce', cfg.nonce || '');

    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then(function(response){
      return response.json();
    });
  }

  // تابع debounce ساده برای کاهش فراخوانی‌های تکراری
  function debounce(fn, delay) {
    var timer;
    return function(){
      var context = this, args = arguments;
      clearTimeout(timer);
      timer = setTimeout(function(){
        fn.apply(context, args);
      }, delay);
    };
  }

  /**
   * Lazy-load one league panel inside tab shortcode.
   * This prevents parsing/loading all leagues on initial page load.
   */
  function loadLazyPanel(wrap, panel, done){
    if(!panel) {
      if(done) done();
      return;
    }

    if(panel.getAttribute('data-f360ls-loaded') === '1') {
      if(done) done();
      return;
    }

    if(panel.dataset.f360lsLoading === '1') {
      if(done) done();
      return;
    }

    var leagueId = panel.getAttribute('data-f360ls-lazy-id') || panel.getAttribute('data-f360ls-panel') || '';

    if(!leagueId) {
      if(done) done();
      return;
    }

    panel.dataset.f360lsLoading = '1';
    panel.classList.add('is-loading');

    panel.innerHTML = '<div class="f360ls-lazy-placeholder is-loading">در حال دریافت اطلاعات...</div>';

    ajaxRequest({
      action: 'f360ls_refresh',
      module: 'league',
      id: leagueId,
      force: '0'
    })
    .then(function(json){
      if(json && json.success && json.data && json.data.html) {
        panel.innerHTML = json.data.html;
        panel.setAttribute('data-f360ls-loaded', '1');
      } else {
        panel.innerHTML = '<div class="f360ls-empty">داده این رقابت دریافت نشد.</div>';
      }
    })
    .catch(function(){
      panel.innerHTML = '<div class="f360ls-empty">خطا در دریافت داده. دوباره تلاش کنید.</div>';
    })
    .finally(function(){
      panel.dataset.f360lsLoading = '0';
      panel.classList.remove('is-loading');

      if(done) done();
    });
  }

  /**
   * Lazy-load sidebar widgets like [f360_mini_table].
   * This makes pages with sidebar shortcode load fast.
   */
  function initLazyWidgets(scope){
    var cfg = getConfig();

    if(!cfg.ajaxUrl) return;

    scope = scope || document;

    var widgets = scope.querySelectorAll('[data-f360ls-lazy-widget="1"]');

    if(!widgets.length) return;

    function load(widget){
      if(!widget) return;

      if(widget.getAttribute('data-f360ls-loaded') === '1') return;
      if(widget.dataset.f360lsLoading === '1') return;

      widget.dataset.f360lsLoading = '1';

      ajaxRequest({
        action: 'f360ls_refresh',
        module: widget.getAttribute('data-f360ls-module') || 'mini_table',
        id: widget.getAttribute('data-f360ls-id') || '',
        ids: widget.getAttribute('data-f360ls-ids') || '',
        team: widget.getAttribute('data-f360ls-team') || '',
        limit: widget.getAttribute('data-f360ls-limit') || '5',
        title: widget.getAttribute('data-f360ls-title') || 'جدول خلاصه',
        force: '0'
      })
      .then(function(json){
        if(json && json.success && json.data && json.data.html) {
          widget.innerHTML = json.data.html;
          widget.setAttribute('data-f360ls-loaded', '1');
        } else {
          widget.innerHTML = '<div class="f360ls-empty">داده سایدبار دریافت نشد.</div>';
        }
      })
      .catch(function(){
        widget.innerHTML = '<div class="f360ls-empty">خطا در دریافت داده سایدبار.</div>';
      })
      .finally(function(){
        widget.dataset.f360lsLoading = '0';
      });
    }

    if('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
          if(entry.isIntersecting) {
            load(entry.target);
            observer.unobserve(entry.target);
          }
        });
      }, {
        root: null,
        rootMargin: '250px',
        threshold: 0.01
      });

      widgets.forEach(function(widget){
        observer.observe(widget);
      });
    } else {
      // بهینه‌شده: بارگذاری تنبل بر اساس اسکرول برای مرورگرهای قدیمی
      var pending = Array.prototype.slice.call(widgets);
      
      function checkVisibility() {
        var viewHeight = window.innerHeight;
        var toLoad = [];

        pending = pending.filter(function(widget) {
          var rect = widget.getBoundingClientRect();
          // اگر ویجت در فاصله 250px بالای viewport قرار دارد (rootMargin مشابه Observer)
          if (rect.top < viewHeight + 250 && rect.bottom > -250) {
            load(widget);
            return false; // حذف از صف
          }
          return true;
        });

        if (!pending.length) {
          window.removeEventListener('scroll', scrollHandler);
          window.removeEventListener('resize', scrollHandler);
        }
      }

      var scrollHandler = debounce(checkVisibility, 150);
      window.addEventListener('scroll', scrollHandler, { passive: true });
      window.addEventListener('resize', scrollHandler, { passive: true });
      // بارگذاری اولیه ویجت‌های قابل مشاهده
      checkVisibility();
    }
  }

  function initWrap(wrap){
    if(!wrap) return;

    if(wrap.dataset.f360lsBound === '1') return;
    wrap.dataset.f360lsBound = '1';

    var tabs = wrap.querySelectorAll('.f360ls-tab');
    var search = wrap.querySelector('.f360ls-search-input');
    var filterButtons = wrap.querySelectorAll('.f360ls-filter-buttons button');
    var currentFilter = 'all';
    var isAllLayout = wrap.classList.contains('f360ls-all');

    // کش کردن المان‌های DOM برای افزایش سرعت
    var panelList = wrap.querySelectorAll('.f360ls-panel');
    // نگهداری ارجاع به آرایه‌های داده‌ای هر پنل برای استفاده سریع در filter
    var panelDataCache = []; 
    // هر عضو: { panel: element, sections: [], searchItems: [] }

    function buildCache() {
      panelDataCache = [];
      panelList.forEach(function(panel) {
        var sections = Array.prototype.slice.call(panel.querySelectorAll('[data-content-type]'));
        var searchItems = Array.prototype.slice.call(panel.querySelectorAll('[data-search]'));
        panelDataCache.push({
          panel: panel,
          sections: sections,
          searchItems: searchItems,
          noResults: panel.querySelector('.f360ls-no-results')
        });
      });
    }

    function panels(){
      return panelList;
    }

    function activePanel(){
      return wrap.querySelector('.f360ls-panel.is-active') || panels()[0] || null;
    }

    function targetPanels(){
      if(isAllLayout) {
        return panelDataCache; // آرایه کش شده
      }

      var active = activePanel();
      if (!active) return [];
      // یافتن داده‌های کش شده مربوط به پنل فعال
      for (var i = 0; i < panelDataCache.length; i++) {
        if (panelDataCache[i].panel === active) {
          return [panelDataCache[i]];
        }
      }
      return [];
    }

    function applyFilter(){
      var q = norm(search ? search.value : '');
      var targets = targetPanels();

      targets.forEach(function(data){
        if(!data) return;

        var visible = 0;
        var searchableCount = data.searchItems.length;

        // مخفی/نمایش بخش‌ها
        data.sections.forEach(function(section){
          var type = section.getAttribute('data-content-type');
          var ok = currentFilter === 'all' || currentFilter === type;
          section.classList.toggle('f360ls-is-hidden', !ok);
        });

        var board = data.panel.querySelector('.f360ls-footballi-board');
        if(board) board.setAttribute('data-f360ls-filter', currentFilter);

        // مخفی/نمایش آیتم‌های جست‌وجو
        data.searchItems.forEach(function(item){
          var parent = item.closest('[data-content-type]');
          var type = parent ? parent.getAttribute('data-content-type') : '';

          var typeOk = currentFilter === 'all' || currentFilter === type;
          var textOk = !q || norm(item.getAttribute('data-search')).indexOf(q) !== -1;

          var show = typeOk && textOk;

          item.classList.toggle('f360ls-is-hidden', !show);

          if(show) visible++;
        });

        // نمایش/مخفی پیام بدون نتیجه
        if(data.noResults) {
          data.noResults.style.display = searchableCount > 0 && visible === 0 ? 'block' : 'none';
        }
      });
    }

    // ساخت کش یکبار پس از بارگذاری اولیه (وقتی DOM کامل است)
    buildCache();

    // نسخه debounced برای جست‌وجوی متنی
    var debouncedFilter = debounce(applyFilter, 200);

    tabs.forEach(function(tab){
      tab.addEventListener('click', function(){
        var id = tab.getAttribute('data-f360ls-tab');

        tabs.forEach(function(t){
          t.classList.remove('is-active');
        });

        panels().forEach(function(p){
          p.classList.remove('is-active');
        });

        tab.classList.add('is-active');

        var panel = wrap.querySelector('[data-f360ls-panel="' + id + '"]');

        if(panel) {
          panel.classList.add('is-active');

          loadLazyPanel(wrap, panel, function(){
            // بازسازی کش برای پنل تازه بارگذاری شده
            buildCache();
            // Re-init lazy widgets inside newly-loaded content.
            initLazyWidgets(panel);
            initFavorites(wrap);
            applyFilter();
          });
        }
      });
    });

    if(search) {
      search.addEventListener('input', function() {
        debouncedFilter();
      });
    }

    filterButtons.forEach(function(btn){
      btn.addEventListener('click', function(){
        currentFilter = btn.getAttribute('data-filter') || 'all';

        filterButtons.forEach(function(b){
          b.classList.remove('is-active');
        });

        btn.classList.add('is-active');

        applyFilter();
      });
    });

    // اولین اجرای فیلتر با تأخیر هوشمند برای جلوگیری از مسدود شدن رندر
    if (window.requestIdleCallback) {
      requestIdleCallback(function() {
        applyFilter();
      });
    } else {
      // fallback ایمن: کمی تأخیر با setTimeout
      setTimeout(applyFilter, 50);
    }
  }

  /**
   * Auto refresh only after initial page load.
   * It does not block rendering.
   */
  function initAutoRefresh(wrap){
    if(!wrap) return;

    var cfg = getConfig();

    if(!cfg.ajaxUrl) return;
    if(wrap.getAttribute('data-f360ls-refresh') !== '1') return;

    var interval = parseInt(cfg.refreshInterval || 60, 10);

    if(interval < 15) interval = 15;

    var busy = false;
    var failures = 0;

    function refresh(){
      if(busy) return;

      // Do not refresh while tab is hidden.
      if(document.hidden) return;

      var module = wrap.getAttribute('data-f360ls-module') || '';
      var target;
      var id = wrap.getAttribute('data-f360ls-id') || '';

      if(module === 'tabs') {
        target = wrap.querySelector('.f360ls-panel.is-active');
        id = target ? (target.getAttribute('data-f360ls-panel') || '') : '';
        module = 'league';
      } else {
        target = wrap.querySelector('.f360ls-refresh-target');
      }

      if(!target) return;

      busy = true;
      wrap.classList.add('is-refreshing');

      ajaxRequest({
        action: 'f360ls_refresh',
        module: module,
        id: id,
        ids: wrap.getAttribute('data-f360ls-ids') || '',
        team: wrap.getAttribute('data-f360ls-team') || '',
        limit: wrap.getAttribute('data-f360ls-limit') || '80',
        force: '0'
      })
      .then(function(json){
        if(json && json.success && json.data && json.data.html) {
          target.innerHTML = json.data.html;
          target.setAttribute('data-f360ls-loaded', '1');

          // Re-init lazy widgets in refreshed content.
          initLazyWidgets(target);
          initFavorites(wrap);

          failures = 0;
        } else {
          failures++;
        }
      })
      .catch(function(){
        failures++;
      })
      .finally(function(){
        busy = false;
        wrap.classList.remove('is-refreshing');

        // If repeated failures, slow down by skipping some refreshes.
        if(failures >= 3) {
          setTimeout(function(){
            failures = 0;
          }, 5 * 60 * 1000);
        }
      });
    }

    // First refresh is delayed so it never slows the first paint.
    setTimeout(function(){
      setInterval(refresh, interval * 1000);
    }, 3000);
  }

  function getFavs(){
    try {
      return JSON.parse(localStorage.getItem('f360ls_favs') || '[]');
    } catch(e) {
      return [];
    }
  }

  function setFavs(f){
    localStorage.setItem('f360ls_favs', JSON.stringify(f));
  }

  function initFavorites(scope){
    if(!scope || !scope.querySelectorAll) return;

    var module = scope.classList && scope.classList.contains('f360ls-favorites-module')
      ? scope
      : scope.querySelector('.f360ls-favorites-module');

    if(!module) return;

    // اگر قبلاً event delegation روی این ماژول تنظیم شده، دوباره انجام نشود
    if(module.dataset.f360lsFavDelegated === '1') {
      // فقط رابط کاربری را به‌روز کن
      updateFavUI(module);
      return;
    }
    module.dataset.f360lsFavDelegated = '1';

    // استفاده از event delegation برای عملکرد سریع‌تر
    module.addEventListener('click', function(e) {
      var btn = e.target.closest('[data-f360ls-fav-team]');
      if (!btn) return;

      var team = btn.getAttribute('data-f360ls-fav-team');
      var favs = getFavs();
      var index = favs.indexOf(team);

      if(index === -1) {
        favs.push(team);
      } else {
        favs.splice(index, 1);
      }
      setFavs(favs);
      updateFavUI(module);
    });

    updateFavUI(module);
  }


  function updateFavUI(module) {
    var favs = getFavs();

    module.querySelectorAll('[data-f360ls-fav-team]').forEach(function(btn){
      var team = btn.getAttribute('data-f360ls-fav-team');
      btn.classList.toggle('is-selected', favs.indexOf(team) !== -1);
    });

    module.querySelectorAll('[data-f360ls-match-teams]').forEach(function(card){
      var teams = (card.getAttribute('data-f360ls-match-teams') || '').split('|');

      var ok = favs.length === 0 || teams.some(function(t){
        return favs.indexOf(t) !== -1;
      });

      card.classList.toggle('f360ls-is-hidden', !ok);
      card.classList.toggle('is-favorite-match', ok && favs.length > 0);
    });
  }

  ready(function(){
    // مقداردهی اولیه با کمترین تأخیر روی رندر
    if (window.requestIdleCallback) {
      requestIdleCallback(function() {
        initAllWraps();
      });
    } else {
      // اگر requestIdleCallback پشتیبانی نشد، از setTimeout با تأخیر کوتاه استفاده کن
      setTimeout(initAllWraps, 1);
    }

    function initAllWraps() {
      document.querySelectorAll('.f360ls-wrap').forEach(function(wrap){
        initWrap(wrap);
        initAutoRefresh(wrap);
        initFavorites(wrap);
      });

      // Init lazy sidebar widgets globally.
      initLazyWidgets(document);
    }
  });
})();