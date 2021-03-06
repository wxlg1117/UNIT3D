<?php
/**
 * NOTICE OF LICENSE
 *
 * UNIT3D is open-sourced software licensed under the GNU General Public License v3.0
 * The details is bundled with this project in the file LICENSE.txt.
 *
 * @project    UNIT3D
 * @license    https://www.gnu.org/licenses/agpl-3.0.en.html/ GNU Affero General Public License v3.0
 * @author     HDVinnie
 */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Group;
use App\User;
use App\Peer;
use App\Torrent;
use App\Comment;
use App\Client;
use App\Post;
use App\Topic;
use App\PrivateMessage;
use App\Follow;
use App\History;
use App\Warning;
use App\Note;
use App\Mail\InviteUser;
use \Toastr;
use Image;
use Carbon\Carbon;

/**
 * User Management
 *
 *
 *
 */
class UserController extends Controller
{
    /**
     * Get Members List
     *
     * @access public
     * @return view users.members
     */
    public function members()
    {
        $users = User::orderBy('created_at', 'DESC')->paginate(50);

        return view('user.members', ['users' => $users]);
    }

    /**
     * Search for members (member use)
     *
     * @access public
     *
     */
    public function userSearch(Request $request)
    {
        $search = $request->input('search');
        $users = User::where([
            ['username', 'like', '%' . $request->input('username') . '%'],
        ])->paginate(25);
        $users->setPath('?username=' . $request->input('username'));
        return view('user.members')->with('users', $users);
    }

    /**
     * Get User Profile
     *
     * @access public
     * @return view user.profile
     */
    public function profil($username, $id)
    {
        $user = User::findOrFail($id);
        $groups = Group::all();
        $hiscount = History::where('user_id', '=', $id)->count();
        $seedtime = History::where('user_id', '=', $id)->sum('seedtime');

        $num_uploads = Torrent::where('user_id', '=', $id)->count();
        $num_downloads = History::where('user_id', '=', $id)->where('actual_downloaded', '>', 0)->count();
        $achievements = $user->unlockedAchievements();
        $followers = Follow::where('target_id', '=', $id)->get();
        $tor_comments = Comment::where('user_id', '=', $id)->where('torrent_id', '>', 0)->count();
        $art_comments = Comment::where('user_id', '=', $id)->where('article_id', '>', 0)->count();
        $req_comments = Comment::where('user_id', '=', $id)->where('requests_id', '>', 0)->count();
        $topics = Topic::where('first_post_user_id', '=', $id)->count();
        $posts = Post::where('user_id', '=', $id)->count();
        $warnings = Warning::where('user_id', '=', $id)->whereNotNull('torrent')->where('active', '=', '1')->take(3)->get();
        $hitrun = Warning::where('user_id', '=', $id)->orderBy('created_at', 'DESC')->get();
        $notes = Note::where('user_id', '=', $id)->count();

        return view('user.profil', ['user' => $user, 'groups' => $groups, 'num_uploads' => $num_uploads, 'num_downloads' => $num_downloads, 'achievements' => $achievements, 'followers' => $followers, 'notes' => $notes,
            'seedtime' => $seedtime, 'hiscount' => $hiscount, 'tor_comments' => $tor_comments, 'art_comments' => $art_comments, 'req_comments' => $req_comments, 'topics' => $topics, 'posts' => $posts, 'warnings' => $warnings, 'hitrun' => $hitrun]);
    }

    /**
     * Edit User Profile
     *
     * @access public
     * @return void
     *
     */
    public function editProfil(Request $request, $username, $id)
    {
        $user = auth()->user();
        // Requetes post only
        if ($request->isMethod('POST')) {
            // Avatar
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                if (in_array($image->getClientOriginalExtension(), ['jpg', 'JPG', 'jpeg', 'bmp', 'png', 'PNG', 'tiff', 'gif', 'GIF']) && preg_match('#image/*#', $image->getMimeType())) {
                    $filename = $user->username . '.' . $image->getClientOriginalExtension();
                    $path = public_path('/files/img/' . $filename);
                    Image::make($image->getRealPath())->fit(150, 150)->save($path);
                    $user->image = $user->username . '.' . $image->getClientOriginalExtension();
                }
            }
            // Define data
            $user->title = $request->input('title');
            $user->about = $request->input('about');
            $user->signature = $request->input('signature');
            // Save the user
            $user->save();

            // Activity Log
            \LogActivity::addToLog("Member " . $user->username . " has updated there profile.");

            return redirect()->route('profil', ['username' => $user->username, 'id' => $user->id])->with(Toastr::success('Your Account Was Updated Successfully!', 'Yay!', ['options']));
        }

        return view('user.edit_profil', ['user' => $user]);
    }

    /**
     * User Account Settings
     *
     * @access public
     * @return view user.settings
     */
    public function settings($username, $id)
    {
        $user = auth()->user();
        return view('user.settings', ['user' => $user]);
    }

    /**
     * Change User Account Settings
     *
     * @access public
     * @return view user.settings
     */
    public function changeSettings(Request $request, $username, $id)
    {
        $user = auth()->user();
        if ($request->isMethod('POST')) {
            $user->style = (int)$request->input('theme');
            $css_url = $request->input('custom_css');
            if (isset($css_url) && filter_var($css_url, FILTER_VALIDATE_URL) === false) {
                return redirect()->route('profil', ['username' => $user->username, 'id' => $user->id])->with(Toastr::error('The URL for the external CSS stylesheet is invalid, try it again with a valid URL.', 'Whoops!', ['options']));
            } else {
                $user->custom_css = $css_url;
            }

            $user->nav = $request->input('sidenav');
            $user->hidden = $request->input('onlinehide');
            $user->private_profile = $request->input('private_profile');
            $user->peer_hidden = $request->input('peer_hidden');
            $user->show_poster = $request->input('show_poster');
            $user->ratings = $request->input('ratings');
            if (config('auth.TwoStepEnabled') == true) {
                $user->twostep = $request->input('twostep');
            }
            $user->save();

            // Activity Log
            \LogActivity::addToLog("Member " . $user->username . " has changed there account settings.");

            return redirect()->route('profil', ['username' => $user->username, 'id' => $user->id])->with(Toastr::success('Your Account Was Updated Successfully!', 'Yay!', ['options']));
        } else {
            return redirect()->route('profil', ['username' => $user->username, 'id' => $user->id])->with(Toastr::error('Something Went Wrong!', 'Whoops!', ['options']));
        }
    }

    /**
     * User Password Change
     *
     * @access protected
     *
     */
    protected function changePassword(Request $request)
    {
        $this->validate($request, [
            'current_password' => 'required',
            'new_password' => 'required|min:6|confirmed',
            'new_password_confirmation' => 'required|min:6',
        ]);
        $usr = User::findOrFail(auth()->user()->id);
        if (Hash::check($request->current_password, $usr->password)) {
            $usr->fill([
                'password' => Hash::make($request->new_password)
            ])->save();
            return redirect('/login')->with(Toastr::success('Your Password Has Been Reset', 'Yay!', ['options']));
        } else {
            return redirect()->route('profil', ['username' => $user->username, 'id' => $user->id])->with(Toastr::error('Your Password Was Incorrect!', 'Whoops!', ['options']));
        }
    }

    /**
     * User Email Change
     *
     * @access protected
     *
     */
    protected function changeEmail(Request $request, $username, $id)
    {
        $user = auth()->user();
        $v = validator($request->all(), [
            'current_password' => 'required',
            'new_email' => 'required',
        ]);
        if ($v->passes()) {
            if ($request->isMethod('POST')) {
                $user->email = $request->input('new_email');
                $user->save();

                // Activity Log
                \LogActivity::addToLog("Member " . $user->username . " has changed there email address on file.");

                return redirect()->route('profil', ['username' => $user->username, 'id' => $user->id])->with(Toastr::success('Your Email Was Updated Successfully!', 'Yay!', ['options']));
            } else {
                return redirect()->route('profil', ['username' => $user->username, 'id' => $user->id])->with(Toastr::error('Your Password Was Incorrect!', 'Whoops!', ['options']));
            }
        }
    }

    /**
     * Change User PID
     *
     * @access public
     * @return view user.settings
     */
    public function changePID(Request $request, $username, $id)
    {
        $user = auth()->user();
        if ($request->isMethod('post')) {
            $user->passkey = md5(uniqid() . time() . microtime());
            $user->save();
            return redirect()->route('profil', ['username' => $user->username, 'id' => $user->id])->with(Toastr::success('Your PID Was Changed Successfully!', 'Yay!', ['options']));
        } else {
            return redirect()->route('profil', ['username' => $user->username, 'id' => $user->id])->with(Toastr::error('Something Went Wrong!', 'Whoops!', ['options']));
        }
    }

    /**
     * My SeedBoxes
     *
     *
     * @access public
     * @param $id Id User
     * @return view::make user.clients
     */
    public function clients($username, $id)
    {
        $user = auth()->user();
        $cli = Client::where('user_id', '=', $user->id)->get();
        return view('user.clients', ['user' => $user, 'clients' => $cli]);
    }

    protected function authorizeClient(Request $request, $username, $id)
    {
        $v = validator($request->all(), [
            'password' => 'required',
            'ip' => 'required|ipv4|unique:clients,ip',
            'client_name' => 'required|alpha_num',
        ]);

        $user = auth()->user();
        if ($v->passes()) {
            if (Hash::check($request->input('password'), $user->password)) {
                if (Client::where('user_id', '=', $user->id)->get()->count() >= config('other.max_cli')) {
                    return redirect()->route('user_clients', ['username' => $user->username, 'id' => $user->id])->with(Toastr::error('Max Clients Reached!', 'Whoops!', ['options']));
                }
                $cli = new Client;
                $cli->user_id = $user->id;
                $cli->name = $request->input('client_name');
                $cli->ip = $request->input('ip');
                $cli->save();
                return redirect()->route('user_clients', ['username' => $user->username, 'id' => $user->id])->with(Toastr::success('Client Has Been Added!', 'Yay', ['options']));
            } else {
                return redirect()->route('user_clients', ['username' => $user->username, 'id' => $user->id])->with(Toastr::error('Password Invalid!', 'Whoops!', ['options']));
            }
        } else {
            return redirect()->route('user_clients', ['username' => $user->username, 'id' => $user->id])->with(Toastr::error('All required values not received or IP is already registered by a member.', 'Whoops!', ['options']));
        }
    }

    protected function removeClient(Request $request, $username, $id)
    {
        $v = validator($request->all(), [
            'cliid' => 'required|exists:clients,id',
            'userid' => 'required|exists:users,id',
        ]);

        $user = auth()->user();
        if ($v->passes()) {
            $cli = Client::where('id', '=', $request->input('cliid'));
            $cli->delete();
            return redirect()->route('user_clients', ['username' => $user->username, 'id' => $user->id])->with(Toastr::success('Client Has Been Removed!', 'Yay!', ['options']));
        } else {
            return redirect()->route('user_clients', ['username' => $user->username, 'id' => $user->id])->with(Toastr::error('Unable to remove this client.', 'Whoops!', ['options']));
        }
    }

    public function getWarnings($username, $id)
    {
        if (auth()->user()->group->is_modo) {
            $user = User::findOrFail($id);
            $warnings = Warning::where('user_id', '=', $user->id)->with(['torrenttitle', 'warneduser'])->orderBy('active', 'DESC')->paginate(25);
            $warningcount = Warning::where('user_id', '=', $id)->count();

            return view('user.warninglog', ['warnings' => $warnings, 'warningcount' => $warningcount, 'user' => $user]);
        } else {
            abort(403, 'Unauthorized action.');
        }
    }

    public function deactivateWarning($id)
    {
        if (auth()->user()->group->is_modo) {
            $staff = auth()->user();
            $warning = Warning::findOrFail($id);
            $warning->expires_on = Carbon::now();
            $warning->active = 0;
            $warning->save();
            PrivateMessage::create(['sender_id' => $staff->id, 'reciever_id' => $warning->user_id, 'subject' => "Hit and Run Warning Deactivated", 'message' => $staff->username . " has decided to deactivate your warning for torrent " . $warning->torrent . " You lucked out! [color=red][b]THIS IS AN AUTOMATED SYSTEM MESSAGE, PLEASE DO NOT REPLY![/b][/color]"]);

            return redirect()->route('warninglog', ['username' => $warning->warneduser->username, 'id' => $warning->warneduser->id])->with(Toastr::success('Warning Was Successfully Deactivated', 'Yay!', ['options']));
        } else {
            abort(403, 'Unauthorized action.');
        }
    }

    public function myUploads($username, $id)
    {
        $user = User::findOrFail($id);
        if (auth()->user()->group->is_modo || auth()->user()->id == $user->id) {
            $torrents = Torrent::withAnyStatus()->sortable(['created_at' => 'desc'])->where('user_id', '=', $user->id)->paginate(50);
            return view('user.uploads', ['user' => $user, 'torrents' => $torrents]);
        } else {
            abort(403, 'Unauthorized action.');
        }
    }

    public function myActive($username, $id)
    {
        $user = User::findOrFail($id);
        if (auth()->user()->group->is_modo || auth()->user()->id == $user->id) {
            $active = Peer::sortable(['created_at' => 'desc'])->where('user_id', '=', $user->id)->with('torrent')->distinct('hash')->paginate(50);
            return view('user.active', ['user' => $user, 'active' => $active]);
        } else {
            abort(403, 'Unauthorized action.');
        }
    }

    public function myHistory($username, $id)
    {
        $user = User::findOrFail($id);
        if (auth()->user()->group->is_modo || auth()->user()->id == $user->id) {
            $his_upl = History::where('user_id', '=', $id)->sum('actual_uploaded');
            $his_upl_cre = History::where('user_id', '=', $id)->sum('uploaded');
            $his_downl = History::where('user_id', '=', $id)->sum('actual_downloaded');
            $his_downl_cre = History::where('user_id', '=', $id)->sum('downloaded');
            $history = History::sortable(['created_at' => 'desc'])->where('user_id', '=', $user->id)->paginate(50);
            return view('user.history', ['user' => $user, 'history' => $history, 'his_upl' => $his_upl, 'his_upl_cre' => $his_upl_cre, 'his_downl' => $his_downl, 'his_downl_cre' => $his_downl_cre]);
        } else {
            abort(403, 'Unauthorized action.');
        }
    }
}
