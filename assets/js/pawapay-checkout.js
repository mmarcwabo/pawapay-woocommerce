(function () {
    'use strict';

    function initMnoCards() {
        var cards = document.querySelectorAll('.pawapay-mno-card');
        var input = document.getElementById('pawapay_mno');

        if ( ! input || ! cards.length ) return;

        cards.forEach(function (card) {
            function select() {
                cards.forEach(function (c) { c.classList.remove('pawapay-selected'); });
                card.classList.add('pawapay-selected');
                input.value = card.dataset.mno;
            }

            card.addEventListener('click', select);
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    select();
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', initMnoCards);
    document.body.addEventListener('updated_checkout', initMnoCards);
})();
