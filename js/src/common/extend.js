import Extend from 'flarum/common/extenders';
import Model from 'flarum/common/Model';
import Discussion from 'flarum/common/models/Discussion';

export class ResurfaceBump extends Model {}
Object.assign(ResurfaceBump.prototype, {
  source: Model.attribute('source'),
  createdAt: Model.attribute('createdAt', Model.transformDate),
  discussion: Model.hasOne('discussion'),
  user: Model.hasOne('user'),
});

export default [
  new Extend.Store().add('resurface-bumps', ResurfaceBump),
  new Extend.Model(Discussion)
    .attribute('resurfaceBumpedAt', Model.transformDate)
    .attribute('resurfaceCanBump')
    .attribute('resurfaceNextBumpAt', Model.transformDate)
    .attribute('resurfaceBumpsLeft')
    .attribute('resurfaceCooldown')
    .attribute('resurfaceCanSchedule')
    .attribute('resurfaceCanViewLog')
    .attribute('resurfaceSchedule'),
];
