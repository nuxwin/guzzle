<?php

namespace GuzzleHttp\Event;

/**
 * Guzzle event emitter.
 */
interface EmitterInterface
{
    /**
     * Binds a listener to a specific event.
     *
     * @param string   $eventName Name of the event to bind to.
     * @param callable $listener  Listener to invoke when triggered.
     * @param int      $priority  The higher this value, the earlier an event listener will be triggered in the chain (defaults to 0)
     */
    public function on($eventName, callable $listener, $priority = 0);

    /**
     * Binds a listener to a specific event. After the listener is triggered once, it is removed as a listener.
     *
     * @param string   $eventName Name of the event to bind to.
     * @param callable $listener  Listener to invoke when triggered.
     * @param int      $priority  The higher this value, the earlier an event listener will be triggered in the chain (defaults to 0)
     */
    public function once($eventName, callable $listener, $priority = 0);

    /**
     * Removes an event listener from the specified event.
     *
     * @param string   $eventName The event to remove a listener from
     * @param callable $listener  The listener to remove
     */
    public function removeListener($eventName, callable $listener);

    /**
     * Gets the listeners of a specific event or all listeners if no event is specified.
     *
     * @param string $eventName The name of the event. Pass null (the default) to retrieve all listeners.
     *
     * @return array The event listeners for the specified event, or all event listeners by event name.
     *   The format of the array when retrieving a specific event list is an array of callables.
     *   The format of the array when retrieving all listeners is an associative array of arrays of callables.
     */
    public function listeners($eventName = null);

    /**
     * Emits an event to all registered listeners.
     *
     * Each event that is bound to the emitted eventName receives a
     * EventInterface, the name of the event, and the event emitter.
     *
     * @param string         $eventName The name of the event to dispatch.
     * @param EventInterface $event     The event to pass to the event handlers/listeners.
     *
     * @return EventInterface Returns the provided event object
     */
    public function emit($eventName, EventInterface $event);

    /**
     * Adds an event subscriber.
     *
     * The subscriber is asked for all the events he is
     * interested in and added as a listener for these events.
     *
     * @param SubscriberInterface $subscriber The subscriber.
     */
    public function addSubscriber(SubscriberInterface $subscriber);

    /**
     * Removes an event subscriber.
     *
     * @param SubscriberInterface $subscriber Subscriber to remove.
     */
    public function removeSubscriber(SubscriberInterface $subscriber);
}