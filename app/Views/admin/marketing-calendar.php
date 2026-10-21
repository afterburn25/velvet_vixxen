<?php
declare(strict_types=1);

// The owner-only controller prepares a read-only snapshot before any output.
// Do not construct a MarketingStore or invoke a publication/delivery runner here.
if (!isset($editorialCalendar) || !is_array($editorialCalendar)) {
    throw new RuntimeException('The publishing calendar is unavailable. Reload this page.');
}
$calendarTimezone = new DateTimeZone($editorialCalendar['timezone']);
$calendarMonth = new DateTimeImmutable($editorialCalendar['month'] . '-01T12:00:00', $calendarTimezone);
$calendarMonthLabel = $calendarMonth->format('F Y');
$calendarTypes = ['all' => 'All content'] + $editorialCalendar['types'];
$calendarSelectedType = $editorialCalendar['type'];
$calendarCurrentMonth = substr($editorialCalendar['today'], 0, 7);
$calendarUrl = static fn(string $month, ?string $type = null): string => base_url('admin/?' . http_build_query([
    'page' => 'marketing', 'tab' => 'calendar', 'month' => $month,
    'type' => $type ?? $calendarSelectedType,
], '', '&', PHP_QUERY_RFC3986));
$calendarFirstCell = $calendarMonth->modify('-' . $calendarMonth->format('w') . ' days');
$calendarDays = [];
foreach ($editorialCalendar['events'] as $calendarEvent) $calendarDays[$calendarEvent['date']][] = $calendarEvent;
$calendarEventAnchor = static fn(string $key): string => 'calendar-event-' . substr(hash('sha256', $key), 0, 24);
$calendarDateLabel = static function (string $date) use ($calendarTimezone): string {
    return (new DateTimeImmutable($date . 'T12:00:00', $calendarTimezone))->format('l, F j');
};
$calendarTimeLabel = static function (string $at) use ($calendarTimezone): string {
    return (new DateTimeImmutable($at))->setTimezone($calendarTimezone)->format('g:i:s A T');
};
$calendarActionLabel = static fn(string $action): string => ucfirst(str_replace('_', ' ', $action));
?>
<div class="marketing-calendar" data-publishing-calendar data-calendar-month="<?= velvet_marketing_escape($editorialCalendar['month']) ?>" data-calendar-filter="<?= velvet_marketing_escape($calendarSelectedType) ?>" data-calendar-timezone="<?= velvet_marketing_escape($editorialCalendar['timezone']) ?>">
  <header class="marketing-card marketing-calendar-intro">
    <div>
      <span class="marketing-eyebrow">Your publishing schedule</span>
      <h2>Publishing calendar</h2>
      <p>See saved publication dates, expiry dates and campaign schedules together. Open an item to change its settings.</p>
      <p class="marketing-calendar-timezone">Times shown in <strong><?= velvet_marketing_escape($editorialCalendar['timezone']) ?></strong>. Status reflects the saved item now.</p>
    </div>
    <a class="marketing-button" href="<?= velvet_marketing_escape(base_url('admin/?page=marketing&tab=posts')) ?>">New post</a>
  </header>

  <section class="marketing-card marketing-calendar-planner" aria-labelledby="calendar-month-heading">
    <form class="marketing-form marketing-calendar-filter" method="get" action="<?= velvet_marketing_escape(base_url('admin/')) ?>" data-calendar-filter-form>
      <input type="hidden" name="page" value="marketing">
      <input type="hidden" name="tab" value="calendar">
      <label>Month<input type="month" name="month" min="1970-01" max="9998-12" value="<?= velvet_marketing_escape($editorialCalendar['month']) ?>" required></label>
      <label>Content type<select name="type"><?php foreach ($calendarTypes as $typeKey => $typeLabel): ?><option value="<?= velvet_marketing_escape($typeKey) ?>" <?= $calendarSelectedType === $typeKey ? 'selected' : '' ?>><?= velvet_marketing_escape($typeLabel) ?></option><?php endforeach; ?></select></label>
      <button class="marketing-button" type="submit">Show calendar</button>
    </form>
    <div class="marketing-calendar-monthbar">
      <div>
        <h2 id="calendar-month-heading"><?= velvet_marketing_escape($calendarMonthLabel) ?></h2>
        <p data-calendar-event-count><?= count($editorialCalendar['events']) ?> dated <?= count($editorialCalendar['events']) === 1 ? 'event' : 'events' ?><?= $calendarSelectedType !== 'all' ? ' · ' . velvet_marketing_escape($calendarTypes[$calendarSelectedType]) : '' ?></p>
      </div>
      <nav class="marketing-calendar-navigation" aria-label="Calendar month">
        <?php if ($editorialCalendar['month'] > '1970-01'): ?><a href="<?= velvet_marketing_escape($calendarUrl($calendarMonth->modify('-1 month')->format('Y-m'))) ?>" aria-label="Previous month" data-calendar-previous><span aria-hidden="true">←</span> Previous</a><?php endif; ?>
        <a href="<?= velvet_marketing_escape($calendarUrl($calendarCurrentMonth)) ?>" data-calendar-today>This month</a>
        <?php if ($editorialCalendar['month'] < '9998-12'): ?><a href="<?= velvet_marketing_escape($calendarUrl($calendarMonth->modify('+1 month')->format('Y-m'))) ?>" aria-label="Next month" data-calendar-next>Next <span aria-hidden="true">→</span></a><?php endif; ?>
      </nav>
    </div>
    <table class="marketing-calendar-grid" data-calendar-grid aria-labelledby="calendar-month-heading">
      <caption class="marketing-calendar-sr-only">Publication events for <?= velvet_marketing_escape($calendarMonthLabel) ?> in <?= velvet_marketing_escape($editorialCalendar['timezone']) ?>. Select a day with events to read its full agenda.</caption>
      <thead><tr><?php foreach (['Sun' => 'Sunday', 'Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday'] as $shortDay => $fullDay): ?><th scope="col"><abbr title="<?= $fullDay ?>"><?= $shortDay ?></abbr></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php for ($calendarWeek = 0; $calendarWeek < 6; $calendarWeek++): ?><tr>
        <?php for ($calendarWeekday = 0; $calendarWeekday < 7; $calendarWeekday++):
          $calendarDay = $calendarFirstCell->modify('+' . ($calendarWeek * 7 + $calendarWeekday) . ' days');
          $calendarDate = $calendarDay->format('Y-m-d');
          $calendarInsideMonth = $calendarDay->format('Y-m') === $editorialCalendar['month'];
          $calendarIsToday = $calendarDate === $editorialCalendar['today'];
          $calendarDayEvents = $calendarInsideMonth ? ($calendarDays[$calendarDate] ?? []) : [];
        ?>
        <td data-calendar-day="<?= $calendarDate ?>" class="<?= !$calendarInsideMonth ? 'is-outside' : '' ?> <?= $calendarIsToday ? 'is-today' : '' ?>">
          <?php if ($calendarDayEvents !== []): ?>
            <a class="marketing-calendar-day-link" href="#calendar-day-<?= $calendarDate ?>" aria-label="<?= velvet_marketing_escape($calendarDay->format('l, F j, Y') . ', ' . count($calendarDayEvents) . (count($calendarDayEvents) === 1 ? ' event' : ' events')) ?>">
              <time datetime="<?= $calendarDate ?>" <?= $calendarIsToday ? 'aria-current="date"' : '' ?>><?= $calendarDay->format('j') ?></time>
              <span class="marketing-calendar-day-count" aria-hidden="true"><?= count($calendarDayEvents) ?> <span class="marketing-calendar-count-word"><?= count($calendarDayEvents) === 1 ? 'event' : 'events' ?></span></span>
            </a>
            <ul class="marketing-calendar-day-events" aria-hidden="true">
              <?php foreach (array_slice($calendarDayEvents, 0, 2) as $calendarDayEvent): ?><li data-type="<?= velvet_marketing_escape($calendarDayEvent['type']) ?>"><span><?= velvet_marketing_escape($calendarActionLabel($calendarDayEvent['action'])) ?></span> <?= velvet_marketing_escape($calendarDayEvent['title']) ?></li><?php endforeach; ?>
              <?php if (count($calendarDayEvents) > 2): ?><li class="marketing-calendar-more">+<?= count($calendarDayEvents) - 2 ?> more in agenda</li><?php endif; ?>
            </ul>
          <?php else: ?>
            <span class="marketing-calendar-day-number"><time datetime="<?= $calendarDate ?>" <?= $calendarIsToday ? 'aria-current="date"' : '' ?>><?= $calendarDay->format('j') ?></time></span>
          <?php endif; ?>
        </td>
        <?php endfor; ?>
      </tr><?php endfor; ?>
      </tbody>
    </table>
    <p class="marketing-calendar-grid-help">Choose a day with events to jump to its agenda. Dates do not change an item’s saved status or access settings.</p>
  </section>

  <?php foreach ($editorialCalendar['notices'] as $calendarNotice): ?><p class="marketing-notice marketing-calendar-notice" data-calendar-notice><?= velvet_marketing_escape($calendarNotice) ?></p><?php endforeach; ?>

  <section class="marketing-card marketing-calendar-agenda" data-calendar-agenda aria-labelledby="calendar-agenda-heading">
    <div class="marketing-calendar-section-heading"><span class="marketing-eyebrow">The month at a glance</span><h2 id="calendar-agenda-heading">Agenda</h2></div>
    <?php if ($editorialCalendar['events'] === []): ?>
      <div class="marketing-calendar-empty" data-calendar-empty>
        <h3>No dated items this month</h3>
        <p>No saved <?= $calendarSelectedType === 'all' ? 'content' : velvet_marketing_escape(strtolower($calendarTypes[$calendarSelectedType])) ?> dates fall in <?= velvet_marketing_escape($calendarMonthLabel) ?>. Choose another month or add a date in the item’s editor.</p>
        <?php if ($calendarSelectedType !== 'all'): ?><a class="marketing-button marketing-button-secondary" href="<?= velvet_marketing_escape($calendarUrl($editorialCalendar['month'], 'all')) ?>">Show all content</a><?php endif; ?>
      </div>
    <?php else: ?>
      <?php foreach ($calendarDays as $calendarDate => $calendarDayEvents): ?>
      <section class="marketing-calendar-agenda-day" id="calendar-day-<?= velvet_marketing_escape($calendarDate) ?>" aria-labelledby="calendar-day-title-<?= velvet_marketing_escape($calendarDate) ?>">
        <h3 id="calendar-day-title-<?= velvet_marketing_escape($calendarDate) ?>"><time datetime="<?= velvet_marketing_escape($calendarDate) ?>"><?= velvet_marketing_escape($calendarDateLabel($calendarDate)) ?></time><?= $calendarDate === $editorialCalendar['today'] ? ' · Today' : '' ?></h3>
        <ul class="marketing-calendar-event-list">
          <?php foreach ($calendarDayEvents as $calendarEvent): ?>
          <li id="<?= $calendarEventAnchor($calendarEvent['key']) ?>" data-calendar-event="<?= velvet_marketing_escape($calendarEvent['key']) ?>" data-calendar-source-id="<?= velvet_marketing_escape($calendarEvent['source_id']) ?>" data-calendar-type="<?= velvet_marketing_escape($calendarEvent['type']) ?>" data-calendar-action="<?= velvet_marketing_escape($calendarEvent['action']) ?>">
            <div class="marketing-calendar-event-time"><time datetime="<?= velvet_marketing_escape($calendarEvent['at']) ?>"><?= velvet_marketing_escape($calendarTimeLabel($calendarEvent['at'])) ?></time><span><?= velvet_marketing_escape($calendarActionLabel($calendarEvent['action'])) ?></span></div>
            <div class="marketing-calendar-event-content">
              <span class="marketing-calendar-type" data-type="<?= velvet_marketing_escape($calendarEvent['type']) ?>"><?= velvet_marketing_escape($calendarEvent['type_label']) ?></span>
              <h4><?= velvet_marketing_escape($calendarEvent['title']) ?></h4>
              <p class="marketing-calendar-event-state" data-calendar-state><?= velvet_marketing_escape($calendarEvent['state']) ?></p>
              <?php if ($calendarEvent['detail'] !== ''): ?><p class="marketing-calendar-event-detail"><?= velvet_marketing_escape($calendarEvent['detail']) ?></p><?php endif; ?>
            </div>
            <?php if ($calendarEvent['edit_path'] !== ''): ?><a class="marketing-button marketing-button-secondary marketing-calendar-edit" data-calendar-edit href="<?= velvet_marketing_escape(base_url($calendarEvent['edit_path'])) ?>"><?= velvet_marketing_escape($calendarEvent['edit_label']) ?></a><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <?php if ($editorialCalendar['undated'] !== []): ?>
  <details class="marketing-card marketing-calendar-undated" data-calendar-undated>
    <summary>Without a date or needing review <span><?= count($editorialCalendar['undated']) ?></span></summary>
    <p>These saved items have no usable date to place in the calendar. They are shown for the selected content type, regardless of month.</p>
    <ul class="marketing-calendar-event-list">
      <?php foreach ($editorialCalendar['undated'] as $calendarEvent): ?>
      <li data-calendar-undated-item="<?= velvet_marketing_escape($calendarEvent['key']) ?>" data-calendar-source-id="<?= velvet_marketing_escape($calendarEvent['source_id']) ?>" data-calendar-type="<?= velvet_marketing_escape($calendarEvent['type']) ?>">
        <div class="marketing-calendar-event-content">
          <span class="marketing-calendar-type" data-type="<?= velvet_marketing_escape($calendarEvent['type']) ?>"><?= velvet_marketing_escape($calendarEvent['type_label']) ?></span>
          <h3><?= velvet_marketing_escape($calendarEvent['title']) ?></h3>
          <p class="marketing-calendar-event-state" data-calendar-state><?= velvet_marketing_escape($calendarEvent['state']) ?></p>
          <?php if ($calendarEvent['detail'] !== ''): ?><p class="marketing-calendar-event-detail"><?= velvet_marketing_escape($calendarEvent['detail']) ?></p><?php endif; ?>
        </div>
        <?php if ($calendarEvent['edit_path'] !== ''): ?><a class="marketing-button marketing-button-secondary marketing-calendar-edit" data-calendar-edit href="<?= velvet_marketing_escape(base_url($calendarEvent['edit_path'])) ?>"><?= velvet_marketing_escape($calendarEvent['edit_label']) ?></a><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
</div>
