<?php
defined( 'ABSPATH' ) || exit;

class WC_PawaPay_I18n {

    public static function load(): void {
        load_plugin_textdomain( 'wc-pawapay', false, dirname( plugin_basename( WC_PAWAPAY_PLUGIN_FILE ) ) . '/languages' );

        add_filter( 'gettext_wc-pawapay', [ self::class, 'filter_french' ], 10, 2 );
    }

    public static function filter_french( string $translation, string $text ): string {
        if ( ! str_starts_with( determine_locale(), 'fr' ) ) {
            return $translation;
        }

        $map = [
            'Enable PawaPay Mobile Money' => 'Activer PawaPay Mobile Money',
            'Payment method title shown to customers.' => 'Titre du moyen de paiement affiché aux clients.',
            'Mobile Money' => 'Mobile Money',
            'Pay with your mobile money wallet.' => 'Payez avec votre portefeuille mobile money.',
            'Use PawaPay sandbox (testing)' => 'Utiliser le sandbox PawaPay (test)',
            'Found in PawaPay Dashboard → Developers. Sandbox and production tokens are different.' => 'Tableau de bord PawaPay → Developers. Les jetons sandbox et production sont différents.',
            'Use the WooCommerce order currency when the selected operator supports it.' => 'Utiliser la devise de la commande WooCommerce si l’opérateur la prend en charge.',
            'Fallback currency if the order currency is not supported by the operator.' => 'Devise de secours si la devise de la commande n’est pas prise en charge.',
            'Countries where customers can pay. Leave empty to show every selected operator.' => 'Pays dans lesquels les clients peuvent payer. Vide = tous les opérateurs sélectionnés.',
            'Operators shown at checkout. Enable the matching countries above.' => 'Opérateurs affichés au checkout. Cochez aussi les pays correspondants.',
            'Optional extra operators, one per line: LABEL|PROVIDER_CODE' => 'Opérateurs supplémentaires, une ligne : LIBELLÉ|CODE',
            'Copy this URL into PawaPay Dashboard → Callback URLs → Deposits only:' => 'Copiez cette URL dans PawaPay Dashboard → Callback URLs → Deposits uniquement :',
            'Mobile money number' => 'Numéro Mobile Money',
            'International format without + (e.g. 243810000000)' => 'Format international sans + (ex. 243810000000)',
            'Country' => 'Pays',
            'Mobile money operator' => 'Opérateur mobile',
            'Please enter your mobile money number.' => 'Veuillez entrer votre numéro mobile money.',
            'Invalid number. Use international format without + (e.g. 243810000000).' => 'Numéro invalide. Utilisez le format international sans + (ex. 243810000000).',
            'Please select your mobile money operator.' => 'Veuillez sélectionner votre opérateur mobile.',
            'That operator is not enabled for this store.' => 'Cet opérateur n’est pas activé pour cette boutique.',
            'Payment error: ' => 'Erreur de paiement : ',
            'Waiting for mobile money confirmation via PawaPay.' => 'En attente de confirmation mobile money via PawaPay.',
            'Payment declined: ' => 'Paiement refusé : ',
            'Refunds must be processed from the PawaPay dashboard.' => 'Les remboursements doivent être traités depuis le tableau de bord PawaPay.',
            'Confirm the payment on your phone. This page updates when PawaPay confirms the deposit.' => 'Confirmez le paiement sur votre téléphone. Cette page se met à jour lorsque PawaPay confirme le dépôt.',
            'Charge currencies' => 'Devises de débit',
            'Currencies this gateway may send to PawaPay. The list is the shop/switcher currencies plus PawaPay catalog currencies. Checkout then keeps only those the selected operator supports.' => 'Devises que cette passerelle peut envoyer à PawaPay. La liste combine les devises de la boutique/du sélecteur et celles du catalogue PawaPay. Le checkout ne garde que celles prises en charge par l’opérateur choisi.',
            'Exchange rates' => 'Taux de change',
            'Used only when the charge currency differs from the order and no Aelia/WOOCS converter is present. One per line, units of that currency per 1 shop-base unit. Example: CDF=2800' => 'Utilisé seulement si la devise de débit diffère de la commande et qu’Aelia/WOOCS n’est pas présent. Une ligne par devise, unités pour 1 unité de la devise boutique. Exemple : CDF=2800',
            'Payment currency' => 'Devise de paiement',
            'Only currencies enabled for this shop and for the selected operator.' => 'Uniquement les devises activées pour cette boutique et pour l’opérateur sélectionné.',
            'Please choose a payment currency supported by this operator.' => 'Veuillez choisir une devise de paiement prise en charge par cet opérateur.',
            'No exchange rate is available for that payment currency. Add a rate in PawaPay settings or install a currency switcher.' => 'Aucun taux de change n’est disponible pour cette devise. Ajoutez un taux dans les réglages PawaPay ou installez un sélecteur de devises.',
            'GitHub update token' => 'Jeton GitHub pour les mises à jour',
            'Payment to' => 'Paiement à',
            'For' => 'Pour',
            'Amount' => 'Montant',
            'Phone number' => 'Numéro de téléphone',
            'Enter your phone number' => 'Entrez votre numéro de téléphone',
            'Operator' => 'Opérateur',
            'Powered by PawaPay' => 'Propulsé par PawaPay',
            'Your order' => 'Votre commande',
            'Order %s' => 'Commande %s',
            '%1$s × %2$s' => '%1$s × %2$s',
            'Check PawaPay status' => 'Vérifier le statut PawaPay',
            'Optional. Only needed for a private fork. Fine-grained PAT with Contents: Read. WC_PAWAPAY_GITHUB_TOKEN in wp-config overrides this field. Leave empty for the public official repo.' => 'Facultatif. Seulement pour un fork privé. Jeton fine-grained Contents: Read. WC_PAWAPAY_GITHUB_TOKEN dans wp-config remplace ce champ. Laissez vide pour le dépôt officiel public.',
            'Copy one of these URLs into PawaPay Dashboard → Callback URLs → Deposits only:' => 'Copiez une de ces URL dans PawaPay Dashboard → Callback URLs → Deposits uniquement :',
            'Use the REST URL if the pretty permalink 404s.' => 'Utilisez l’URL REST si le permilien renvoie 404.',
            'Still waiting for PawaPay. Keep this page open or check the order later — confirmation can also arrive by webhook.' => 'Toujours en attente de PawaPay. Laissez cette page ouverte ou consultez la commande plus tard — la confirmation peut aussi arriver par webhook.',
        ];

        return $map[ $text ] ?? $translation;
    }
}
