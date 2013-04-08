<?php
  use Moar\Template\Block as T;
  include dirname(__FILE__) . '/base.php';

  T::block('content', 'trim');
?>
Page 1 content.
<?php
  T::endblock('content');

  T::block('title', array('trim'));
?>
PAGE 1
<?php
  T::endblock('title');
