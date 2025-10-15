// V2Board 签到功能的 Redux Model
// 需要添加到 umi.js 文件中

const CheckinModel = {
    name: "checkin",
    state: {
        status: null,
        loading: false,
        checkinLoading: false
    },
    reducers: {
        setState(state, action) {
            return {
                ...state,
                ...action.payload
            };
        }
    },
    effects: {
        *getStatus(action, { call, put }) {
            try {
                yield put({
                    type: "setState",
                    payload: { loading: true }
                });
                
                const response = yield call(fetch, '/api/v1/user/checkin/status', {
                    method: 'GET',
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('authorization'),
                        'Content-Type': 'application/json'
                    }
                });
                
                const result = yield response.json();
                
                yield put({
                    type: "setState",
                    payload: { 
                        status: result.data,
                        loading: false 
                    }
                });
                
                if (action.complete) {
                    action.complete(result);
                }
            } catch (error) {
                yield put({
                    type: "setState",
                    payload: { loading: false }
                });
                
                if (action.error) {
                    action.error(error);
                }
            }
        },
        
        *checkin(action, { call, put }) {
            try {
                yield put({
                    type: "setState",
                    payload: { checkinLoading: true }
                });
                
                const response = yield call(fetch, '/api/v1/user/checkin/checkin', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('authorization'),
                        'Content-Type': 'application/json'
                    }
                });
                
                const result = yield response.json();
                
                yield put({
                    type: "setState",
                    payload: { checkinLoading: false }
                });
                
                if (action.complete) {
                    action.complete(result);
                }
            } catch (error) {
                yield put({
                    type: "setState",
                    payload: { checkinLoading: false }
                });
                
                if (action.error) {
                    action.error(error);
                }
            }
        }
    }
};

// 需要在 umi.js 中注册这个 model
// 找到类似这样的代码并添加：
// l.model(o()({
//     namespace: "checkin"
// }, CheckinModel))
