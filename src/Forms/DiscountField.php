<?php

namespace XD\EventTickets\Discounts\Forms;

use SilverStripe\Core\Validation\ValidationResult;
use XD\EventTickets\Discounts\Model\Discount;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\Validator;

/**
 * Class DiscountForm
 *
 * @package XD\EventTickets
 */
class DiscountField extends TextField
{
    /**
     * @var DiscountForm
     */
    protected $form;

    /**
     * Validate the discount if it is set by...
     * Checking if it exists
     * Checking if it has uses left
     * Checking if it has a valid date
     * Checking if the event is valid
     * Checking if the discount is valid on one of the registered members
     * TODO: move all these checks to the discount itself? make a method that returns a error message
     *
     * @param Validator $validator
     *
     * @return bool
     *
     * @throws \ValidationException
     */
    public function validate(): ValidationResult
    {
        $result = ValidationResult::create();
        // If no discount is set continue doing default validation
        if (!isset($this->value) || empty($this->value)) {
            return parent::validate();
        }

        // Brute-force protection: at most N wrong codes per session per window
        if ($this->tooManyAttempts()) {
            $result->addFieldError($this->getName(), _t(
                __CLASS__ . '.VALIDATION_TOO_MANY',
                'Too many attempts, please try again later'
            ));

            return $result;
        }

        $reservation = $this->form->getReservation();

        /** @var Discount $discount */
        // Check if the discount exists
        if (!$discount = Discount::get()->find('Code', trim((string) $this->value))) {
            $this->registerFailedAttempt();
            $result->addFieldError($this->getName(), _t(
                __CLASS__ . '.VALIDATION_NOT_FOUND',
                'The entered coupon is not found'
            ));

            return $result;
        }

        // Check if the discount is already used
        if (!$discount->validateUses($reservation)) {
            $result->addFieldError($this->getName(), _t(
                __CLASS__ . '.VALIDATION_USED_CHECK',
                'The entered coupon is already used'
            ));

            return $result;
        }

        // Check if the coupon is expired
        if (!$discount->validateDate()) {
            $result->addFieldError($this->getName(), _t(
                __CLASS__ . '.VALIDATION_DATE_CHECK',
                'The coupon is expired'
            ));

            return $result;
        }

        // Check if the coupon is allowed on this event
        if (!$discount->validateEvents($reservation->TicketPage())) {
            $result->addFieldError($this->getName(), _t(
                __CLASS__ . '.VALIDATION_EVENT_CHECK',
                'The coupon is not allowed on this event'
            ));

            return $result;
        }

        if (!$discount->validateOncePerEmail($reservation, $this->submittedMainContactEmail($reservation))) {
            $result->addFieldError($this->getName(), _t(
                __CLASS__ . '.VALIDATION_EMAIL_CHECK',
                'The coupon is only usable once'
            ));

            return $result;
        }

        if (!$discount->validateTicketType($reservation)) {
            $result->addFieldError($this->getName(), _t(
                __CLASS__ . '.VALIDATION_TICKET_TYPE_CHECK',
                'Your missing the product this coupon is allowed on'
            ));

            return $result;
        }

        // If groups are required check if one of the attendees is in the required group
        if (!$checkMember = $discount->validateGroups()) {
            foreach ($reservation->Attendees() as $attendee) {
                /** @var Attendee $attendee */
                if ($attendee->Member()->exists() && $member = $attendee->Member()) {
                    if ($checkMember = $discount->validateGroups($member)) {
                        // If one of the member is part of the group validate the discount
                        break;
                    } else {
                        $checkMember = false;
                    }
                }
            }
        }

        if (!$checkMember) {
            $result->addFieldError($this->getName(), _t(
                __CLASS__ . 'DiscountField.VALIDATION_MEMBER_CHECK',
                'None of the attendees is allowed to use this coupon'
            ));

            return $result;
        }

        // One discount per reservation: replace any other discount instead of stacking them
        foreach ($reservation->PriceModifiers() as $modifier) {
            if ($modifier instanceof Discount && (int) $modifier->ID !== (int) $discount->ID) {
                $reservation->PriceModifiers()->remove($modifier);
            }
        }

        $discount->write();
        $reservation->PriceModifiers()->add($discount);
        $reservation->calculateTotal();
        $reservation->write();
        return $result;
    }

    /**
     * Max wrong codes per session within the window (config: max_failed_attempts / attempt_window).
     */
    private static $max_failed_attempts = 10;

    private static $attempt_window = 900;

    private function formRequest()
    {
        $controller = $this->form ? $this->form->getController() : null;
        return $controller ? $controller->getRequest() : null;
    }

    private function attemptsSession()
    {
        $request = $this->formRequest();
        return $request ? $request->getSession() : null;
    }

    private function recentAttempts(): array
    {
        $session = $this->attemptsSession();
        $window = (int) static::config()->get('attempt_window');
        $now = time();
        $attempts = $session ? (array) $session->get('DiscountFieldAttempts') : [];
        return array_values(array_filter($attempts, fn($t) => is_int($t) && $t > $now - $window));
    }

    private function tooManyAttempts(): bool
    {
        return count($this->recentAttempts()) >= (int) static::config()->get('max_failed_attempts');
    }

    private function registerFailedAttempt(): void
    {
        if ($session = $this->attemptsSession()) {
            $attempts = $this->recentAttempts();
            $attempts[] = time();
            $session->set('DiscountFieldAttempts', $attempts);
        }
    }

    /**
     * The main contact's email from the form being submitted (the reservation's main contact is only set after
     * this validation), falling back to the stored main contact.
     */
    private function submittedMainContactEmail($reservation): ?string
    {
        $request = $this->formRequest();
        $posted = $request ? $request->postVar('Attendee') : null;
        if (is_array($posted)) {
            $emailFieldIDs = $reservation->TicketPage()->Fields()->filter('Name', 'Email')->column('ID');
            foreach ($posted as $attendeeID => $values) {
                if (!is_array($values) || empty($values['Main'])) {
                    continue;
                }
                foreach ($emailFieldIDs as $fieldID) {
                    if (isset($values[$fieldID])) {
                        $value = is_array($values[$fieldID]) ? reset($values[$fieldID]) : $values[$fieldID];
                        if (is_string($value) && $value !== '') {
                            return $value;
                        }
                    }
                }
            }
        }

        return $reservation->MainContact()->exists() ? $reservation->MainContact()->getEmail() : null;
    }
}
