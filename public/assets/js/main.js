(() => {
  const supportsPointerMotion = window.matchMedia('(pointer: fine)').matches;
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const initializeSidebar = () => {
    const sidebar = document.querySelector('.sapas-sidebar');
    const openButton = document.querySelector('[data-sidebar-open]');
    const closeButton = document.querySelector('[data-sidebar-close]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const desktopQuery = window.matchMedia('(min-width: 1024px)');

    if (!(sidebar instanceof HTMLElement) || !(openButton instanceof HTMLButtonElement)) {
      return;
    }

    const focusableSelector = [
      'a[href]',
      'button:not([disabled])',
      'input:not([disabled])',
      'select:not([disabled])',
      'textarea:not([disabled])',
      '[tabindex]:not([tabindex="-1"])',
    ].join(',');

    let previouslyFocused = null;
    sidebar.tabIndex = -1;

    const setSidebarAvailable = (available) => {
      sidebar.inert = !available;
      sidebar.setAttribute('aria-hidden', available ? 'false' : 'true');
    };

    const syncSidebarAccessibility = () => {
      if (desktopQuery.matches) {
        sidebar.classList.remove('is-open');
        document.body.classList.remove('sapas-drawer-open');
        openButton.setAttribute('aria-expanded', 'false');
        setSidebarAvailable(true);

        if (backdrop instanceof HTMLElement) {
          backdrop.hidden = true;
        }

        return;
      }

      setSidebarAvailable(sidebar.classList.contains('is-open'));
    };

    const closeSidebar = ({ restoreFocus = true } = {}) => {
      sidebar.classList.remove('is-open');
      document.body.classList.remove('sapas-drawer-open');
      openButton.setAttribute('aria-expanded', 'false');

      if (backdrop instanceof HTMLElement) {
        backdrop.hidden = true;
      }

      if (!desktopQuery.matches) {
        setSidebarAvailable(false);
      }

      if (restoreFocus && previouslyFocused instanceof HTMLElement) {
        previouslyFocused.focus();
      }
    };

    const openSidebar = () => {
      previouslyFocused = document.activeElement;
      setSidebarAvailable(true);
      sidebar.classList.add('is-open');
      document.body.classList.add('sapas-drawer-open');
      openButton.setAttribute('aria-expanded', 'true');

      if (backdrop instanceof HTMLElement) {
        backdrop.hidden = false;
      }

      window.requestAnimationFrame(() => {
        const firstFocusable = sidebar.querySelector(focusableSelector);
        if (closeButton instanceof HTMLButtonElement) {
          closeButton.focus();
        } else if (firstFocusable instanceof HTMLElement) {
          firstFocusable.focus();
        }
      });
    };

    openButton.addEventListener('click', openSidebar);
    closeButton?.addEventListener('click', () => closeSidebar());
    backdrop?.addEventListener('click', () => closeSidebar());

    sidebar.addEventListener('click', (event) => {
      if (event.target instanceof Element && event.target.closest('a') && !desktopQuery.matches) {
        closeSidebar({ restoreFocus: false });
      }
    });

    document.addEventListener('keydown', (event) => {
      if (!sidebar.classList.contains('is-open') || desktopQuery.matches) {
        return;
      }

      if (event.key === 'Escape') {
        event.preventDefault();
        closeSidebar();
        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      const focusable = Array.from(sidebar.querySelectorAll(focusableSelector))
        .filter((element) => element instanceof HTMLElement && !element.hidden && element.offsetParent !== null);

      if (focusable.length === 0) {
        event.preventDefault();
        sidebar.focus();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    desktopQuery.addEventListener('change', () => {
      syncSidebarAccessibility();
    });

    syncSidebarAccessibility();
  };

  const initializeSidebarNavPointer = () => {
    const navigation = document.querySelector('.sapas-sidebar-nav');

    if (!(navigation instanceof HTMLElement)) {
      return;
    }

    const activeLink = navigation.querySelector('.sapas-nav-link.is-active');
    let currentLink = activeLink instanceof HTMLElement ? activeLink : null;

    const placePointer = (link) => {
      if (!(link instanceof HTMLElement)) {
        navigation.style.setProperty('--sapas-nav-pointer-opacity', '0');
        return;
      }

      const navigationBounds = navigation.getBoundingClientRect();
      const linkBounds = link.getBoundingClientRect();

      navigation.style.setProperty('--sapas-nav-pointer-y', `${linkBounds.top - navigationBounds.top}px`);
      navigation.style.setProperty('--sapas-nav-pointer-height', `${linkBounds.height}px`);
      navigation.style.setProperty('--sapas-nav-pointer-opacity', '1');
      currentLink = link;
    };

    const restoreActivePointer = () => placePointer(activeLink);
    window.requestAnimationFrame(restoreActivePointer);

    if (supportsPointerMotion && !prefersReducedMotion) {
      navigation.addEventListener('pointerover', (event) => {
        const link = event.target instanceof Element ? event.target.closest('.sapas-nav-link') : null;

        if (link instanceof HTMLElement && navigation.contains(link)) {
          placePointer(link);
        }
      });
      navigation.addEventListener('pointerleave', restoreActivePointer);
    }

    navigation.addEventListener('focusin', (event) => {
      const link = event.target instanceof Element ? event.target.closest('.sapas-nav-link') : null;
      if (link instanceof HTMLElement) {
        placePointer(link);
      }
    });
    navigation.addEventListener('focusout', () => {
      window.requestAnimationFrame(() => {
        if (!navigation.contains(document.activeElement)) {
          restoreActivePointer();
        }
      });
    });
    window.addEventListener('resize', () => placePointer(currentLink || activeLink), { passive: true });
  };

  const initializeScrollableTables = () => {
    const label = document.body.dataset.scrollableTableLabel || 'Scrollable data table';
    const wrappers = Array.from(document.querySelectorAll('.sapas-data-table-scroll'));

    if (wrappers.length === 0) {
      return;
    }

    const updateWrapper = (wrapper) => {
      if (!(wrapper instanceof HTMLElement)) {
        return;
      }

      const isScrollable = wrapper.scrollWidth > wrapper.clientWidth + 1;
      wrapper.classList.toggle('is-scrollable', isScrollable);

      if (isScrollable) {
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        if (!wrapper.hasAttribute('aria-label')) {
          wrapper.setAttribute('aria-label', label);
        }
      } else {
        wrapper.removeAttribute('tabindex');
        wrapper.removeAttribute('role');
        if (wrapper.getAttribute('aria-label') === label) {
          wrapper.removeAttribute('aria-label');
        }
      }
    };

    const updateAll = () => wrappers.forEach(updateWrapper);
    updateAll();

    if ('ResizeObserver' in window) {
      const observer = new ResizeObserver((entries) => {
        entries.forEach((entry) => updateWrapper(entry.target));
      });
      wrappers.forEach((wrapper) => observer.observe(wrapper));
    } else {
      window.addEventListener('resize', updateAll, { passive: true });
    }
  };

  const initializePasswordVisibility = () => {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
      if (!(button instanceof HTMLButtonElement)) {
        return;
      }

      const inputId = button.getAttribute('aria-controls');
      const input = inputId ? document.getElementById(inputId) : null;

      if (!(input instanceof HTMLInputElement)) {
        return;
      }

      button.addEventListener('click', () => {
        const willShow = input.type === 'password';
        input.type = willShow ? 'text' : 'password';
        button.setAttribute('aria-pressed', willShow ? 'true' : 'false');
        button.setAttribute('aria-label', willShow ? button.dataset.hideLabel || '' : button.dataset.showLabel || '');

        const showIcon = button.querySelector('[data-password-show-icon]');
        const hideIcon = button.querySelector('[data-password-hide-icon]');
        if (showIcon instanceof HTMLElement) showIcon.hidden = willShow;
        if (hideIcon instanceof HTMLElement) hideIcon.hidden = !willShow;
      });
    });
  };

  const initializeFlashDismissal = () => {
    document.querySelectorAll('[data-flash-dismiss]').forEach((button) => {
      button.addEventListener('click', () => {
        const message = button.closest('[data-flash-message]');
        if (message instanceof HTMLElement) {
          message.remove();
        }
      });
    });
  };

  const initializeWorkScheduleEditor = () => {
    document.querySelectorAll('[data-work-schedule-row]').forEach((row) => {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      const toggle = row.querySelector('[data-working-day-toggle]');
      const fields = Array.from(row.querySelectorAll('[data-working-day-field]'))
        .filter((field) => field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement);
      const breakEnabled = row.querySelector('[data-break-enabled]');
      const breakFields = Array.from(row.querySelectorAll('[data-break-field]'))
        .filter((field) => field instanceof HTMLInputElement);
      const timeSummary = row.querySelector('[data-day-summary="time"]');
      const breakSummary = row.querySelector('[data-day-summary="break"]');
      const graceSummary = row.querySelector('[data-day-summary="grace"]');
      const workingState = row.querySelector('[data-working-state]');
      const workingToggleLabel = row.querySelector('[data-working-toggle-label]');
      const dashLabel = row.dataset.dashLabel || '—';
      const noBreakLabel = row.dataset.noBreakLabel || 'No unpaid break';
      const minutesLabel = row.dataset.minutesLabel || 'minutes';
      const nextDayLabel = row.dataset.nextDayLabel || 'next day';
      const onLabel = row.dataset.onLabel || 'On';
      const offLabel = row.dataset.offLabel || 'Off';

      if (!(toggle instanceof HTMLInputElement)) {
        return;
      }

      const minutesFor = (input) => {
        if (!(input instanceof HTMLInputElement) || !/^\d{2}:\d{2}$/.test(input.value)) {
          return null;
        }

        const [hours, minutes] = input.value.split(':').map(Number);
        return (hours * 60) + minutes;
      };

      const durationBetween = (start, end, overnight = false) => {
        if (start === null || end === null) {
          return null;
        }

        let adjustedEnd = end;
        if (overnight || adjustedEnd < start) {
          adjustedEnd += 1440;
        }

        const duration = adjustedEnd - start;
        return duration > 0 ? duration : null;
      };

      const recalculateMinutes = () => {
        const startInput = row.querySelector('input[name$="[start_time]"]');
        const endInput = row.querySelector('input[name$="[end_time]"]');
        const overnightInput = row.querySelector('input[name$="[is_overnight]"]');
        const breakStartInput = row.querySelector('input[name$="[break_start_time]"]');
        const breakEndInput = row.querySelector('input[name$="[break_end_time]"]');
        const breakMinutesInput = row.querySelector('input[name$="[break_minutes]"]');
        const expectedMinutesInput = row.querySelector('input[name$="[expected_minutes]"]');
        const graceInput = row.querySelector('input[name$="[grace_minutes]"]');

        if (!toggle.checked) {
          if (breakMinutesInput instanceof HTMLInputElement) {
            breakMinutesInput.value = '0';
          }
          if (expectedMinutesInput instanceof HTMLInputElement) {
            expectedMinutesInput.value = '0';
          }
          if (timeSummary instanceof HTMLElement) {
            timeSummary.textContent = dashLabel;
          }
          if (breakSummary instanceof HTMLElement) {
            breakSummary.textContent = dashLabel;
          }
          if (graceSummary instanceof HTMLElement) {
            graceSummary.textContent = dashLabel;
          }
          return;
        }

        const scheduled = durationBetween(
          minutesFor(startInput),
          minutesFor(endInput),
          overnightInput instanceof HTMLInputElement && overnightInput.checked,
        );
        const hasBreak = !(breakEnabled instanceof HTMLInputElement) || breakEnabled.checked;
        const breakDuration = hasBreak
          ? durationBetween(
            minutesFor(breakStartInput),
            minutesFor(breakEndInput),
            overnightInput instanceof HTMLInputElement && overnightInput.checked,
          )
          : 0;

        if (breakDuration !== null && breakMinutesInput instanceof HTMLInputElement) {
          breakMinutesInput.value = String(breakDuration);
        }
        if (scheduled !== null && expectedMinutesInput instanceof HTMLInputElement) {
          const breakMinutes = breakMinutesInput instanceof HTMLInputElement ? Number.parseInt(breakMinutesInput.value || '0', 10) : 0;
          expectedMinutesInput.value = String(Math.max(0, scheduled - (Number.isFinite(breakMinutes) ? breakMinutes : 0)));
        }
        if (timeSummary instanceof HTMLElement && startInput instanceof HTMLInputElement && endInput instanceof HTMLInputElement) {
          const overnightText = overnightInput instanceof HTMLInputElement && overnightInput.checked ? ` (${nextDayLabel})` : '';
          timeSummary.textContent = startInput.value && endInput.value ? `${startInput.value}-${endInput.value}${overnightText}` : dashLabel;
        }
        if (breakSummary instanceof HTMLElement && breakStartInput instanceof HTMLInputElement && breakEndInput instanceof HTMLInputElement) {
          breakSummary.textContent = hasBreak && breakStartInput.value && breakEndInput.value ? `${breakStartInput.value}-${breakEndInput.value}` : noBreakLabel;
        }
        if (graceSummary instanceof HTMLElement && graceInput instanceof HTMLInputElement) {
          graceSummary.textContent = `${graceInput.value || '0'} ${minutesLabel}`;
        }
      };

      const update = () => {
        if (workingState instanceof HTMLElement) {
          workingState.textContent = toggle.checked ? onLabel : offLabel;
        }
        if (workingToggleLabel instanceof HTMLElement) {
          workingToggleLabel.textContent = toggle.checked ? onLabel : offLabel;
        }
        fields.forEach((field) => {
          field.disabled = !toggle.checked;
        });
        if (breakEnabled instanceof HTMLInputElement) {
          breakFields.forEach((field) => {
            field.disabled = !toggle.checked || !breakEnabled.checked;
            if (!breakEnabled.checked) {
              field.value = '';
            }
          });
        }
        row.classList.toggle('is-non-working', !toggle.checked);
        recalculateMinutes();
      };

      toggle.addEventListener('change', update);
      breakEnabled?.addEventListener('change', update);
      fields.forEach((field) => field.addEventListener('change', recalculateMinutes));
      update();
    });

    const rows = Array.from(document.querySelectorAll('[data-work-schedule-row]'))
      .filter((row) => row instanceof HTMLElement);
    const applyWorkingDays = (workingDays) => {
      rows.forEach((row, index) => {
        const day = index + 1;
        const toggle = row.querySelector('[data-working-day-toggle]');
        if (toggle instanceof HTMLInputElement) {
          toggle.checked = workingDays.includes(day);
          toggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    };
    document.querySelectorAll('[data-schedule-preset]').forEach((button) => {
      button.addEventListener('click', () => {
        const preset = button.getAttribute('data-schedule-preset');
        if (preset === 'monday-saturday') {
          applyWorkingDays([1, 2, 3, 4, 5, 6]);
        } else if (preset === 'sunday-thursday') {
          applyWorkingDays([1, 2, 3, 4, 7]);
        } else {
          applyWorkingDays([1, 2, 3, 4, 5]);
        }
      });
    });
    document.querySelector('[data-copy-monday-weekdays]')?.addEventListener('click', () => {
      const monday = rows[0];
      if (!(monday instanceof HTMLElement)) {
        return;
      }
      ['start_time', 'end_time', 'break_start_time', 'break_end_time', 'grace_minutes'].forEach((fieldName) => {
        const source = monday.querySelector(`input[name$="[${fieldName}]"]`);
        rows.slice(1, 5).forEach((row) => {
          const target = row.querySelector(`input[name$="[${fieldName}]"]`);
          if (source instanceof HTMLInputElement && target instanceof HTMLInputElement) {
            target.value = source.value;
            target.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
      });
    });

    const syncConditionalControls = () => {
      document.querySelectorAll('[data-rounding-mode]').forEach((select) => {
        const group = select.closest('fieldset')?.querySelector('[data-rounding-interval]');
        if (group instanceof HTMLElement && select instanceof HTMLSelectElement) {
          group.hidden = select.value === 'exact';
        }
      });
      document.querySelectorAll('[data-approval-toggle]').forEach((toggle) => {
        const form = toggle.closest('form');
        form?.querySelectorAll('[data-approval-field]').forEach((field) => {
          if (field instanceof HTMLInputElement && toggle instanceof HTMLInputElement) {
            field.closest('label')?.toggleAttribute('hidden', !toggle.checked);
          }
        });
      });
    };
    document.addEventListener('change', (event) => {
      if (event.target instanceof Element && (event.target.matches('[data-rounding-mode]') || event.target.matches('[data-approval-toggle]'))) {
        syncConditionalControls();
      }
    });
    syncConditionalControls();
  };

  const initializeEmployeeLiveValidation = () => {
    const form = document.querySelector('[data-employee-form]');
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const email = form.querySelector('[data-live-email]');
    const emailFeedback = form.querySelector('[data-live-email-feedback]');
    const password = form.querySelector('[data-live-password]');
    const passwordFeedback = form.querySelector('[data-live-password-feedback]');
    const confirmation = form.querySelector('[data-live-password-confirmation]');
    const confirmationFeedback = form.querySelector('[data-live-password-confirmation-feedback]');
    const passwordRequired = form.dataset.passwordRequired === '1';
    const setFieldState = (field, state, feedback, message = '') => {
      if (!(field instanceof HTMLInputElement)) {
        return;
      }
      field.classList.toggle('is-invalid', state === 'invalid');
      field.classList.toggle('is-valid', state === 'valid');
      if (state === 'invalid') {
        field.setAttribute('aria-invalid', 'true');
      } else {
        field.removeAttribute('aria-invalid');
      }
      if (feedback instanceof HTMLElement) {
        feedback.textContent = message;
        feedback.classList.toggle('is-error', state === 'invalid');
        feedback.classList.toggle('is-success', state === 'valid');
      }
    };

    const validateEmail = () => {
      if (!(email instanceof HTMLInputElement)) {
        return true;
      }
      const value = email.value.trim();
      const valid = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u.test(value);
      setFieldState(
        email,
        valid ? 'valid' : 'invalid',
        emailFeedback,
        valid ? '' : (form.dataset.emailInvalidMessage || 'Enter a valid email address.')
      );
      return valid;
    };

    const passwordRules = () => {
      const value = password instanceof HTMLInputElement ? password.value : '';
      const optionalBlank = !passwordRequired && value === '';
      const rules = {
        length: value.length >= 10,
        letter: /\p{L}/u.test(value),
        number: /\d/u.test(value),
      };

      form.querySelectorAll('[data-password-rule]').forEach((item) => {
        if (!(item instanceof HTMLElement)) {
          return;
        }
        const key = item.dataset.passwordRule;
        const passed = key ? Boolean(rules[key]) : false;
        item.classList.toggle('is-valid', !optionalBlank && passed);
        item.classList.toggle('is-invalid', !optionalBlank && !passed);
      });

      if (!(password instanceof HTMLInputElement)) {
        return true;
      }
      if (optionalBlank) {
        setFieldState(password, 'neutral', passwordFeedback);
        return true;
      }

      const valid = value.trim() !== '' && Object.values(rules).every(Boolean);
      const whitespaceOnly = value !== '' && value.trim() === '';
      setFieldState(
        password,
        valid ? 'valid' : 'invalid',
        passwordFeedback,
        whitespaceOnly ? (form.dataset.passwordWhitespaceMessage || '') : ''
      );
      return valid;
    };

    const validateConfirmation = () => {
      if (!(password instanceof HTMLInputElement) || !(confirmation instanceof HTMLInputElement)) {
        return true;
      }
      const optionalBlank = !passwordRequired && password.value === '' && confirmation.value === '';
      if (optionalBlank) {
        setFieldState(confirmation, 'neutral', confirmationFeedback);
        return true;
      }
      const valid = confirmation.value !== '' && confirmation.value === password.value;
      setFieldState(
        confirmation,
        valid ? 'valid' : 'invalid',
        confirmationFeedback,
        valid ? '' : (form.dataset.passwordConfirmationMessage || 'Passwords do not match.')
      );
      return valid;
    };

    email?.addEventListener('input', validateEmail);
    email?.addEventListener('blur', validateEmail);
    password?.addEventListener('input', () => {
      passwordRules();
      if (confirmation instanceof HTMLInputElement && confirmation.value !== '') {
        validateConfirmation();
      }
    });
    password?.addEventListener('blur', passwordRules);
    confirmation?.addEventListener('input', validateConfirmation);
    confirmation?.addEventListener('blur', validateConfirmation);

    form.addEventListener('submit', (event) => {
      const checks = [validateEmail(), passwordRules(), validateConfirmation()];
      if (checks.every(Boolean)) {
        return;
      }
      event.preventDefault();
      const firstInvalid = form.querySelector('.sapas-form-control.is-invalid');
      if (firstInvalid instanceof HTMLElement) {
        firstInvalid.focus();
      }
    });
  };

  const initializeLoginLoadingState = () => {
    const form = document.getElementById('loginForm');
    const button = document.getElementById('loginButton');

    if (!(form instanceof HTMLFormElement) || !(button instanceof HTMLButtonElement)) {
      return;
    }

    form.addEventListener('submit', () => {
      button.textContent = button.dataset.loadingText || button.textContent;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
    });
  };

  initializeSidebar();
  initializeSidebarNavPointer();
  initializeScrollableTables();
  initializePasswordVisibility();
  initializeFlashDismissal();
  initializeWorkScheduleEditor();
  initializeEmployeeLiveValidation();
  initializeLoginLoadingState();
})();
