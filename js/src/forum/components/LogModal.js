import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import BumpLog from '../../common/BumpLog';

export default class LogModal extends Modal {
  className() {
    return 'ResurfaceLogModal Modal--medium';
  }

  title() {
    return app.translator.trans('ernestdefoe-resurface.forum.log.title');
  }

  content() {
    return (
      <div className="Modal-body">
        <BumpLog discussion={this.attrs.discussion} />
      </div>
    );
  }
}
