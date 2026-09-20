<?php

namespace App\Authorization;

final class Permissions
{
    public const ACCESS_BACKOFFICE = 'access.backoffice';

    public const PRODUCTS_MANAGE = 'products.manage';

    public const STOCK_VIEW_STATUS = 'stock.view_status';

    public const STOCK_VIEW_QUANTITIES = 'stock.view_quantities';

    public const STOCK_ADJUST = 'stock.adjust';

    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_RECORD_PAYMENT = 'orders.record_payment';

    public const ORDERS_VALIDATE = 'orders.validate';

    public const ORDERS_UPDATE_STATUS = 'orders.update_status';

    public const ORDERS_CANCEL = 'orders.cancel';

    public const ORDERS_COUNTER_PREORDER = 'orders.counter_preorder';

    public const POS_SELL = 'pos.sell';

    public const POS_DISCOUNT = 'pos.discount';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const FINANCE_VIEW = 'finance.view';

    public const CUSTOMERS_VIEW = 'customers.view';

    public const BANNERS_MANAGE = 'banners.manage';

    public const TEAM_MANAGE = 'team.manage';

    public const TEAM_MANAGE_STAFF = 'team.manage_staff';

    public const ACTIVITY_VIEW = 'activity.view';

    /**
     * @return array<string, array{label: string, group: string}>
     */
    public static function catalog(): array
    {
        return [
            self::ACCESS_BACKOFFICE => ['label' => 'Accéder au back-office', 'group' => 'accès'],
            self::PRODUCTS_MANAGE => ['label' => 'Gérer le catalogue (produits, prix, images)', 'group' => 'catalogue'],
            self::STOCK_VIEW_STATUS => ['label' => 'Voir l\'état du stock (en stock / sur commande / rupture)', 'group' => 'stock'],
            self::STOCK_VIEW_QUANTITIES => ['label' => 'Voir les quantités de stock', 'group' => 'stock'],
            self::STOCK_ADJUST => ['label' => 'Corriger / réceptionner le stock', 'group' => 'stock'],
            self::ORDERS_VIEW => ['label' => 'Voir les commandes', 'group' => 'commandes'],
            self::ORDERS_RECORD_PAYMENT => ['label' => 'Enregistrer un paiement', 'group' => 'commandes'],
            self::ORDERS_VALIDATE => ['label' => 'Valider une commande', 'group' => 'commandes'],
            self::ORDERS_UPDATE_STATUS => ['label' => 'Changer le statut d\'une commande', 'group' => 'commandes'],
            self::ORDERS_CANCEL => ['label' => 'Annuler une commande', 'group' => 'commandes'],
            self::ORDERS_COUNTER_PREORDER => ['label' => 'Enregistrer une précommande au comptoir', 'group' => 'commandes'],
            self::POS_SELL => ['label' => 'Vendre en caisse', 'group' => 'caisse'],
            self::POS_DISCOUNT => ['label' => 'Appliquer une remise en caisse', 'group' => 'caisse'],
            self::SETTINGS_MANAGE => ['label' => 'Modifier les paramètres boutique', 'group' => 'configuration'],
            self::FINANCE_VIEW => ['label' => 'Voir le CA, rapports et marges', 'group' => 'finance'],
            self::CUSTOMERS_VIEW => ['label' => 'Voir la liste des clients', 'group' => 'clients'],
            self::BANNERS_MANAGE => ['label' => 'Gérer les bannières du site', 'group' => 'configuration'],
            self::TEAM_MANAGE => ['label' => 'Gérer toute l\'équipe (y compris gérants)', 'group' => 'équipe'],
            self::TEAM_MANAGE_STAFF => ['label' => 'Créer / désactiver secrétaires et caissiers', 'group' => 'équipe'],
            self::ACTIVITY_VIEW => ['label' => 'Consulter le journal d\'activité', 'group' => 'équipe'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::catalog());
    }
}
