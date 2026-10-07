<?php

return ['credit_maturation_days' => max(0, (int) env('CREDIT_MATURATION_DAYS', 0))];
