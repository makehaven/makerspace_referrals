/**
 * @file
 * "Who referred you?" picker: search members, pick a face.
 *
 * Progressive enhancement on purpose. Without this file the field is still a
 * text box and the typed name still reaches staff, exactly as before — nobody
 * is blocked by JavaScript failing to load.
 */

(function (Drupal, once, debounce) {
  'use strict';

  /**
   * Builds one result row.
   */
  function resultButton(member, onPick) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'mh-referral-picker__result';
    button.setAttribute('data-uid', member.uid);

    if (member.photo) {
      var img = document.createElement('img');
      img.src = member.photo;
      img.alt = '';
      img.loading = 'lazy';
      img.className = 'mh-referral-picker__photo';
      button.appendChild(img);
    }
    else {
      var blank = document.createElement('span');
      blank.className = 'mh-referral-picker__photo mh-referral-picker__photo--none';
      blank.setAttribute('aria-hidden', 'true');
      button.appendChild(blank);
    }

    var name = document.createElement('span');
    name.className = 'mh-referral-picker__name';
    name.textContent = member.name;
    button.appendChild(name);

    button.addEventListener('click', function () {
      onPick(member);
    });

    return button;
  }

  Drupal.behaviors.mhReferralPicker = {
    attach: function (context) {
      once('mh-referral-picker', '[data-mh-referral-picker]', context).forEach(function (wrapper) {
        var input = wrapper.querySelector('[data-mh-referral-search]');
        var hidden = wrapper.querySelector('[data-mh-referral-uid]');
        var results = wrapper.querySelector('[data-mh-referral-results]');
        var chosen = wrapper.querySelector('[data-mh-referral-chosen]');

        if (!input || !hidden || !results || !chosen) {
          return;
        }

        results.setAttribute('role', 'listbox');

        function clearChoice() {
          hidden.value = '';
          chosen.textContent = '';
          chosen.classList.remove('is-chosen');
        }

        function pick(member) {
          hidden.value = member.uid;
          input.value = member.name;
          results.innerHTML = '';

          chosen.classList.add('is-chosen');
          chosen.textContent = '';

          var tick = document.createElement('span');
          tick.className = 'mh-referral-picker__tick';
          tick.setAttribute('aria-hidden', 'true');
          tick.textContent = '✓';
          chosen.appendChild(tick);

          var label = document.createElement('span');
          label.textContent = Drupal.t('@name — we will let them know.', {'@name': member.name});
          chosen.appendChild(label);

          var undo = document.createElement('button');
          undo.type = 'button';
          undo.className = 'mh-referral-picker__undo link';
          undo.textContent = Drupal.t('Not them');
          undo.addEventListener('click', function () {
            clearChoice();
            input.value = '';
            input.focus();
          });
          chosen.appendChild(undo);
        }

        function render(list) {
          results.innerHTML = '';
          if (!list.length) {
            return;
          }

          list.forEach(function (member) {
            if (member.more) {
              var hint = document.createElement('p');
              hint.className = 'mh-referral-picker__more description';
              hint.textContent = Drupal.t('More people match — keep typing to narrow it down.');
              results.appendChild(hint);
              return;
            }
            results.appendChild(resultButton(member, pick));
          });
        }

        var search = debounce(function () {
          var term = input.value.trim();

          // Typing after a choice means they are changing their mind.
          if (hidden.value) {
            clearChoice();
          }

          if (term.length < 2) {
            results.innerHTML = '';
            return;
          }

          fetch(Drupal.url('referral/member-search') + '?q=' + encodeURIComponent(term), {
            credentials: 'same-origin',
            headers: {Accept: 'application/json'}
          })
            .then(function (response) {
              if (!response.ok) {
                return {results: []};
              }
              return response.json();
            })
            .then(function (data) {
              render(data.results || []);
            })
            .catch(function () {
              // Search being unavailable must not block the form: the typed
              // name still submits and still reaches staff.
              results.innerHTML = '';
            });
        }, 250);

        input.addEventListener('input', search);
      });
    }
  };
})(Drupal, once, Drupal.debounce);
