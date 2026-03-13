<?php
/**
 * Template Name: Admin Havn – Full bredde
 * Description: Fullbredde-side for Admin Havn (uten temaets innholds-container som låser bredde).
 */

if (!defined('ABSPATH')) { exit; }

get_header();
?>

<main id="primary" class="site-main ah-fullwidth-main" role="main">
  <div class="ah-fullwidth-inner">
    <?php
    while (have_posts()) :
      the_post();
      the_content();
    endwhile;
    ?>
  </div>
</main>

<?php
get_footer();
