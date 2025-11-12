<?php

namespace XD\EventTickets\Discounts\Extensions;

use XD\EventTickets\Discounts\Forms\DiscountField;
use SilverStripe\Core\Extension;

/**
 * Class AddDiscountExtension
 * @package XD\EventTickets\Discounts
 * @property ReservationForm $owner
 */
class AddDiscountExtension extends Extension
{
    public function updateForm()
    {
        $fields = $this->owner->Fields();
        $reservation = $this->owner->getReservation();
        $event = $reservation ? $reservation->TicketPage() : $this->owner->getController();

        if (!$event->DisableDiscountField) {
            $fields->add(
                $field = DiscountField::create('CouponCode', _t(DiscountForm::class . '.CouponCode', 'Coupon code'))
            );
            $field->setForm($this->owner);
        }
    }
}
