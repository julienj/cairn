/*
 * What the demonstration site does wrong on purpose, so that the report of
 * the widget has something to say: the price of the first lamp comes from
 * a request the server refuses (500), which the page reports with
 * console.error and then shows as "NaN €". The filters change the address
 * without loading a page, as an application in one page would.
 */
(function () {
    var price = document.querySelector('[data-price="halo"]');
    if (price) {
        fetch('/api/prices/halo?currency=eur').then(function (response) {
            if (!response.ok) {
                throw new Error('No price for "halo" (' + response.status + ')');
            }

            return response.json();
        }).then(function (data) {
            price.textContent = data.amount + ' €';
        }).catch(function (error) {
            console.error('Price not found', error);
            price.textContent = Number(undefined) + ' €';
        });
    }

    document.querySelectorAll('.filters a').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            window.history.pushState({}, '', link.getAttribute('href'));
            document.querySelectorAll('.filters a').forEach(function (other) { other.removeAttribute('aria-current'); });
            link.setAttribute('aria-current', 'page');
            var material = link.getAttribute('data-material');
            document.querySelectorAll('.card').forEach(function (card) {
                card.hidden = '' !== material && card.getAttribute('data-material') !== material;
            });
            document.title = link.textContent + ' · Atelier Brume';
        });
    });

    var actions = {
        error: function () { undefinedFunctionOfTheDemonstration(); },
        rejection: function () { Promise.reject(new Error('A promise nobody caught')); },
        request: function () {
            var request = new XMLHttpRequest();
            request.open('POST', '/api/cart?token=secret');
            request.send();
        },
    };
    document.querySelectorAll('[data-break]').forEach(function (button) {
        button.addEventListener('click', function () { actions[button.getAttribute('data-break')](); });
    });
})();
