<?php

namespace XD\EventTickets\Discounts\Forms;

use XD\EventTickets\Session\ReservationSession;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;

/**
 * Class DiscountForm
 *
 * @package XD\EventTickets
 */
class DiscountForm extends Form
{
    const DEFAULT_NAME = 'DiscountForm';

    public function __construct(RequestHandler $controller)
    {
        $fields = new FieldList([
            DiscountField::create('CouponCode', _t(__CLASS__ . '.CouponCode', 'Coupon code'))
        ]);

        $actions = new FieldList([
            FormAction::create('addDiscount', _t(__CLASS__ . '.AddDiscount', 'Code toevoegen'))
        ]);

        parent::__construct($controller, self::DEFAULT_NAME, $fields, $actions);
        $this->extend('updateForm');
    }

    public function addDiscount()
    {
        $this->getController()->redirectBack();
    }

    public function getReservation()
    {
        return ReservationSession::get();
    }
}
