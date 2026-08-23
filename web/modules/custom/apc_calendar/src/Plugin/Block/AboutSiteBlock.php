<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * "What's it all about?" blurb for the site footer.
 *
 * Defined in code (not a block_content entity) so the copy deploys with the
 * module and needs no per-environment content step.
 *
 * @Block(
 *   id = "apc_calendar_about_site",
 *   admin_label = @Translation("About this site")
 * )
 */
final class AboutSiteBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="apc-about">
  <h2 class="apc-about__title">{{ "What’s it all about?"|t }}</h2>
  <div class="apc-about__body">
    <p>{{ "It’s all about Progress — an upward trend, a positive worldview, being part of the solution. Here on Austin Progressive Calendar, we take Progress to be environmental, economic, emotional, political — anything relevant to human progress."|t }}</p>
    <p>{{ "Browse what’s coming up, submit your own event in a couple of clicks, follow recurring happenings across town, and add any event straight to your Google, Apple, Outlook or Yahoo calendar."|t }}</p>
    <p>{{ "The site is built with Drupal, an open-source PHP content management system, so some of what you’ll find here is devoted to using and understanding Drupal."|t }}</p>
  </div>
</div>',
      '#attached' => ['library' => ['apc_brown/site-footer']],
    ];
  }

}
