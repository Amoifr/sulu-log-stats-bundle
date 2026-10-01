// @flow
import {viewRegistry} from 'sulu-admin-bundle/containers';
import LogStatsDashboard from './views/LogStatsDashboard';

// the view type declared by Amoifr\SuluLogStatsBundle\Admin\LogStatsAdmin
viewRegistry.add('amoifr_log_stats.dashboard', LogStatsDashboard);
