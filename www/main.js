var i18n = new VueI18n ( { locale: 'en-US', messages: messages });
const TOOL_FOLDER_SETTING_NAME = 'yt-dlp_x86_folder';

new Vue ({
  i18n,
  el: '#app',
  data: function () {
    return {
      toolVersion: '',
      updatingTool: false,
      showSettings: true,
      autoHideSettings: true,
      initComplete: false,
      text: '',
      output: '',
      fShowMain: true,
      fShowLog: false,
      fShowServerIsDown: false,
      outHeight: 1,
      tasks: [],
      settings: {
        [TOOL_FOLDER_SETTING_NAME]: '',
        targetFolder: '',
        video: 2,
        videoResolution: -1,
        ffmpegHome: '',
        proxy: '',
        ytDlpOptions: '',
        locale: 'en-US'
      }
    }
  },
  mounted: function () {
    this.init ()
  },
  computed: {
    btnSettingsStr: function () {
      return this.showSettings ? this.$t('btn.hideSettings') : this.$t('btn.showSettings')
    },
    toolVersionStr: function () {
      return this.toolVersion === '' ? '' : this.$t('version') + ' ' + this.toolVersion
    },
    flagSrc: function () {
      return '/flags/' + this.settings.locale + '.jpg'
    },
    addButtonDisabled: function () {
      return this.text === ''
    },
    hasFinishedJobs: function () {
      return this.tasks.reduce ( function ( acc, task ) {
        if ( task.finished ) return acc + 1
        else return acc
      }, 0 )
    },
    hasRunningJobs: function () {
      var ret = this.tasks.reduce ( function ( acc, task ) {
        if ( !task.finished ) return acc + 1
        else return acc
      }, 0 )
      return ret !== 0
    }
  },
  methods: {
    init: function () {
      var vm = this
      ajax ( "/api/getSettings", {} , function ( data ) {
        vm.settings = data
        vm.$i18n.locale = vm.settings.locale
        ajax ( "/api/getTasks", {} , function ( data ) {
          vm.tasks = data.tasks
          vm.initComplete = true
          document.getElementById('app').style.display = 'block'
          vm.focusField ( 'text' )
          vm.getServerStatus ()
          vm.getTasks ()
          vm.getToolVersion ()
        })
      })
    },
    focusField: function ( ref ) {
      var vm = this
      vm.$nextTick ( function () { vm.$refs[ref].focus () })
    },
    updateTool: function () {
      var vm = this
      this.saveSettingsCb ( function () {
        vm.updatingTool = true
        document.getElementById('modal').style.display = 'block'
        ajax ( "/api/updateTool", vm.settings , function ( data ) {
          vm.updatingTool = false
          document.getElementById('modal').style.display = 'none'
          vm.toolVersion = ''
          vm.getToolVersion ()
        })
      })
    },
    showHideSettings: function ( event, val = null ) {
      if ( val === null ) {
        this.autoHideSettings = false
        this.showSettings = !this.showSettings
      }
      else {
        this.showSettings = val
      }
    },
    getToolVersion: function () {
      var vm = this
      ajax ( "/api/getToolVersion", { [TOOL_FOLDER_SETTING_NAME]: vm.settings[TOOL_FOLDER_SETTING_NAME] } , function ( data ) {
        if ( data.version ) vm.toolVersion = data.version
      })
    },
    setLocale: function () {
      this.$i18n.locale = this.settings.locale
    },
    getDownloadProgress: function ( task ) {
      if ( task.finished && !task.errorCode ) return '100';
      return task.downloadProgress;
    },
    getServerStatus: function () {
      var vm = this
      ajax ( "/api/dummy", {} , function ( data, status ) {
        if ( status !== 200 ) {
          vm.fShowServerIsDown = true
          setTimeout ( vm.getServerStatus, 10000 )
        } else {
          vm.fShowServerIsDown = false
          setTimeout(vm.getServerStatus, 3000)
        }
      })
    },
    getStatus: function ( task ) {
      if ( task.exitCode ) return this.$t('status.Error')
      if ( task.finished ) return 'OK'
      if ( task.started ) {
        if ( !task.video ) {
          if ( task.downloadProgress == 100 ) return this.$t('status.CreatingMp3')
        }
        return this.$t('status.Downloading')
      }
      return this.$t('status.Added')
    },
    getClassName: function ( task ) {
      if ( task.exitCode ) return 'error'
      if ( task.finished ) return 'finished'
      if ( task.started ) return 'running'
    },
    getTasks: function () {
      var vm = this
      var allTasksFinished = vm.tasks.reduce ( function ( finished, task ) {
        if ( !finished ) return false
        if ( !task.finished ) return false
        return finished
      }, true )
      if ( !allTasksFinished ) {
        ajax ( "/api/getTasks", {} , function ( data ) {
          vm.tasks = data.tasks
          setTimeout ( vm.getTasks, 500 )
        })
      } else setTimeout ( vm.getTasks, 500 )
    },
    restart: function ( id ) {
        var vm = this;
        ajax ( "/api/restart", { id: id } , function ( data ) {
            vm.tasks = data.tasks
        })
    },
    showLog: function ( show, id ) {
      this.fShowMain = show
      this.fShowLog = !show
      if ( show ) {
        this.focusField ( 'text' )
        return
      }
      var vm = this
      ajax ( "/api/getLog", { id: id } , function ( data ) {
        vm.outHeight = data.output.length + 5
        vm.output = ''
        data.output.forEach ( function ( out ) {
          vm.output = vm.output + '\n' + out
        })
        vm.focusField ( 'log' )
      })
    },
    saveSettingsCb: function ( cb ) {
      var vm = this
      ajax ( "/api/checkSaveSettings", { settings: this.settings } , function ( data ) {
        if ( data['error'] ) alert ( data['val'] + ' ' + vm.$t('error.' + data['error']))
        else {
          if ( typeof cb == 'function' ) cb ()
        }
      })
    },
    saveSettings: function () {
      this.saveSettingsCb ()
    },
    addToQueue: function () {
      var vm = this
      this.saveSettingsCb ( function () {
        ajax ( "/api/newTask", { text: encodeURIComponent ( vm.text ).replace ( /%0A/g, '%20'), settings: vm.settings	} , function ( data ) {
          if ( vm.autoHideSettings ) vm.showHideSettings ( null, false )
          vm.tasks = data.tasks
          vm.text = ''
          vm.focusField ( 'text' )
        })
      })
    },
    deleteTask: function ( task ) {
      var vm = this
      ajax ( "/api/deleteTask", { id: task.id } , function () {
        vm.tasks = vm.tasks.filter ( function ( elem ) {
          return elem.id != task.id
        })
      })
    },
    removeFinishedTasks: function () {
      var vm = this
      this.tasks.forEach ( function ( task ) {
        if ( task.finished ) {
          vm.deleteTask ( task )
        }
      })
    },
    removeAllTasks: function () {
      if (( this.hasRunningJobs ) && ( !confirm ( this.$t('runningAreYouSure') ))) return;
      var vm = this
      ajax ( "/api/deleteAllTasks", {} , function () {
        vm.tasks = []
      })
    },
    openTargetFolder: function () {
      ajax ( "/api/openTargetFolder", {} , function () {})
    }
  }
})

Vue.filter ( 'localDate', function ( value, locale ) {
  var d = new Date ( value * 1000 )
  var currDay = new Date ().getDate()
  if ( currDay == d.getDate() ) return ( "0"+d.getHours ()).slice ( -2 ) + ":" + ("0"+d.getMinutes ()).slice ( -2 )
  return d.toLocaleString ( locale, { day: '2-digit', month: "long", hour: '2-digit', minute: '2-digit' })
})

function ajax ( url, params, cb ) {
  var data = "data=" + JSON.stringify ( params )
  var req = new XMLHttpRequest ()
  req.onreadystatechange = ( function () {
    if ( req.readyState == 4 ) {
      if ( req.responseText != '' ) cb ( JSON.parse ( req.responseText ), req.status )
      else cb ( req.status )
    }
  })
  req.open ( "POST", url, true )
  req.setRequestHeader ( "Content-type", "application/x-www-form-urlencoded" )
  req.send ( data )
}